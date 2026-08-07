<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Enums\BackupEngine;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * One row per site, one column per night.
 *
 * Every backup screen this replaces was built around runs: it queried `backups` and drew a row for
 * each one. That reads fine until you ask the only question that matters — "which client is not
 * protected?" — because a site that has never run a backup has no rows, and a query over rows
 * cannot return a site that has none. Seventeen of forty-two sites were invisible in the entire
 * module for exactly that reason, and four more failed nightly for five days under a screen
 * reporting "Completed: 101".
 *
 * So this starts from the site list and hangs the runs off it, never the other way round. A site
 * with nothing to show still gets a row, an age, and a sentence saying why.
 */
final class FleetLedger
{
    /** How many nights the grid holds. Thirty fits a month of dailies on one screen. */
    public const NIGHTS = 30;

    /** A night with a verified restore point. */
    public const VERIFIED = 3;

    /** A night with a restore point nothing has checked yet. */
    public const UNVERIFIED = 2;

    /** A night something was attempted and failed. */
    public const FAILED = 1;

    /** A night nothing was attempted at all — the state the old screens could not show. */
    public const NOTHING = 0;

    public function __construct(private readonly BackupFailureExplainer $explainer) {}

    /**
     * @param  array<int>|null  $siteIds  null = every site (admin)
     * @return Collection<int, array<string, mixed>>
     */
    public function rows(?array $siteIds = null): Collection
    {
        $sites = Site::query()
            ->with('backupConfig')
            ->when($siteIds !== null, fn ($q) => $q->whereIn('id', $siteIds))
            ->orderBy('name')
            ->get();

        if ($sites->isEmpty()) {
            return collect();
        }

        $ids = $sites->pluck('id')->all();
        $since = Carbon::today()->subDays(self::NIGHTS - 1)->startOfDay();

        $grid = $this->grid($ids, $since);
        $lastValid = $this->lastValidPerSite($ids);
        $lastFull = $this->lastFullPerSite($ids);
        $stored = $this->storedBytesPerSite($ids);
        $lastFailure = $this->lastFailurePerSite($ids);

        return $sites
            ->map(fn (Site $site) => $this->row($site, $grid, $lastValid, $lastFull, $stored, $lastFailure))
            // Scheduled sites first, oldest restore point at the top of that group; sites nobody
            // has asked to back up sink to the bottom. A client whose backup broke five days ago
            // is an incident. A site with no schedule is a decision somebody may have made on
            // purpose — it belongs on the screen, but not above the incident.
            ->sortBy([['attention_rank', 'desc'], ['sort_key', 'desc']])
            ->values();
    }

    /**
     * @param  array<int, array<string, int>>  $grid
     * @param  array<int, Carbon>  $lastValid
     * @param  array<int, Carbon>  $lastFull
     * @param  array<int, int>  $stored
     * @param  array<int, string|null>  $lastFailure
     * @return array<string, mixed>
     */
    private function row(
        Site $site,
        array $grid,
        array $lastValid,
        array $lastFull,
        array $stored,
        array $lastFailure,
    ): array {
        $config = $site->backupConfig;
        $validAt = $lastValid[$site->id] ?? null;
        $scheduled = (bool) $config?->is_enabled;

        // Calendar days, not 24-hour periods: a backup at 23:50 last night is "yesterday", not
        // "0 days". Carbon's diffInDays returns a float, which is how "58.609499307211" reached a
        // column that had room for "58 days".
        $ageDays = $validAt instanceof Carbon
            ? (int) $validAt->copy()->startOfDay()->diffInDays(now()->startOfDay())
            : null;

        return [
            'site' => $site,
            'nights' => $this->nightsFor($grid[$site->id] ?? []),
            'last_valid_at' => $validAt,
            'last_full_at' => $lastFull[$site->id] ?? null,
            'age_days' => $ageDays,
            'stored_bytes' => $stored[$site->id] ?? 0,
            // A site with no config at all has never been told which engine to use; V1 is what it
            // would get, so that is what the row should say.
            'engine' => $config instanceof BackupConfig ? $config->backup_engine : BackupEngine::V1,
            'next_run_at' => $config instanceof BackupConfig && $scheduled ? $config->next_backup_at : null,
            'scheduled' => $scheduled,
            'problem' => $this->problem($site, $validAt, $lastFailure[$site->id] ?? null),
            'attention_rank' => $scheduled ? 1 : 0,
            // "Never" has to outrank every real age rather than sorting as zero: a site nobody has
            // ever backed up is the worst row in its group, not the best.
            'sort_key' => $ageDays ?? PHP_INT_MAX,
        ];
    }

    /**
     * Why this site has no recent restore point, in the order that answers it soonest.
     *
     * Only sites that need one get a sentence — a healthy fleet stays quiet, so every line of text
     * on the screen is a thing to deal with.
     *
     * @return array{message: string, action: string|null}|null
     */
    private function problem(Site $site, ?Carbon $validAt, ?string $rawError): ?array
    {
        $staleAfter = (int) config('backups.stale_after_hours', 36);
        $isStale = $validAt === null || $validAt->lt(now()->subHours($staleAfter));

        if (! $isStale) {
            return null;
        }

        if (! $site->backupConfig?->is_enabled) {
            return [
                'message' => __('Backups are not scheduled for this site.'),
                'action' => __('Turn on a backup schedule to start protecting it.'),
            ];
        }

        if (! $site->is_connected) {
            return [
                'message' => __('The connector is not reachable on this site.'),
                'action' => __('Fix the connection, then the next scheduled run will proceed.'),
            ];
        }

        if ($rawError !== null) {
            return $this->explainer->explain($rawError);
        }

        if ($validAt === null) {
            return [
                'message' => __('No backup has ever completed for this site.'),
                'action' => __('Run one now to find out what stops it.'),
            ];
        }

        return [
            'message' => __('Scheduled, but nothing has run since :when.', ['when' => $validAt->diffForHumans()]),
            'action' => __('Check that the scheduler reaches this site.'),
        ];
    }

    /**
     * Pad the sparse per-date map out to a fixed 30-cell strip, oldest first.
     *
     * Fixed width is the whole point: the right edge is always last night, so the distance from it
     * to the last filled cell IS the age, with no number to read.
     *
     * @param  array<string, int>  $byDate
     * @return list<array{date: string, state: int}>
     */
    private function nightsFor(array $byDate): array
    {
        $cells = [];

        for ($i = self::NIGHTS - 1; $i >= 0; $i--) {
            $date = Carbon::today()->subDays($i)->toDateString();
            $cells[] = ['date' => $date, 'state' => $byDate[$date] ?? self::NOTHING];
        }

        return $cells;
    }

    /**
     * @param  array<int>  $ids
     * @return array<int, array<string, int>>
     */
    private function grid(array $ids, Carbon $since): array
    {
        $rows = Backup::query()
            ->select(['site_id', 'created_at', 'status', 'verified_at'])
            ->whereIn('site_id', $ids)
            ->where('created_at', '>=', $since)
            ->get();

        $grid = [];

        foreach ($rows as $row) {
            $date = $row->created_at?->toDateString();

            if ($date === null) {
                continue;
            }

            $state = match (true) {
                $row->status === \App\Enums\BackupStatus::Completed && $row->verified_at !== null => self::VERIFIED,
                $row->status === \App\Enums\BackupStatus::Completed => self::UNVERIFIED,
                $row->status === \App\Enums\BackupStatus::Failed => self::FAILED,
                // Queued, running and cancelled runs are not restore points and are not failures
                // either. They leave the night as it was rather than claiming it.
                default => self::NOTHING,
            };

            $grid[$row->site_id][$date] = max($grid[$row->site_id][$date] ?? self::NOTHING, $state);
        }

        return $grid;
    }

    /**
     * @param  array<int>  $ids
     * @return array<int, Carbon>
     */
    private function lastValidPerSite(array $ids): array
    {
        return Backup::query()
            ->selectRaw('site_id, max(completed_at) as at')
            ->whereIn('site_id', $ids)
            ->where('status', \App\Enums\BackupStatus::Completed)
            ->whereNotNull('completed_at')
            ->groupBy('site_id')
            ->pluck('at', 'site_id')
            ->map(fn ($at) => Carbon::parse($at))
            ->all();
    }

    /**
     * @param  array<int>  $ids
     * @return array<int, Carbon>
     */
    private function lastFullPerSite(array $ids): array
    {
        return Backup::query()
            ->selectRaw('site_id, max(completed_at) as at')
            ->whereIn('site_id', $ids)
            ->where('status', \App\Enums\BackupStatus::Completed)
            ->where('type', 'full')
            ->whereNotNull('completed_at')
            ->groupBy('site_id')
            ->pluck('at', 'site_id')
            ->map(fn ($at) => Carbon::parse($at))
            ->all();
    }

    /**
     * @param  array<int>  $ids
     * @return array<int, int>
     */
    private function storedBytesPerSite(array $ids): array
    {
        return Backup::query()
            ->selectRaw('site_id, coalesce(sum(file_size), 0) as bytes')
            ->whereIn('site_id', $ids)
            ->where('status', \App\Enums\BackupStatus::Completed)
            ->groupBy('site_id')
            ->pluck('bytes', 'site_id')
            ->map(fn ($bytes) => (int) $bytes)
            ->all();
    }

    /**
     * The error from the most recent failure, but only when it is the most recent run — an old
     * failure a later success already answered is not why the site looks bad today.
     *
     * @param  array<int>  $ids
     * @return array<int, string|null>
     */
    private function lastFailurePerSite(array $ids): array
    {
        $latest = Backup::query()
            ->select(['site_id', 'status', 'error_message', 'created_at'])
            ->whereIn('site_id', $ids)
            ->orderByDesc('created_at')
            ->get()
            ->unique('site_id');

        $errors = [];

        foreach ($latest as $backup) {
            $errors[$backup->site_id] = $backup->status === \App\Enums\BackupStatus::Failed
                ? $backup->error_message
                : null;
        }

        return $errors;
    }
}
