<?php

declare(strict_types=1);

namespace App\Livewire\Backups;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Livewire\Traits\WithSorting;
use App\Livewire\Traits\WithTableFilters;
use App\Livewire\Traits\WithVisibleSites;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Services\Backup\BackupLauncher;
use App\Services\Backup\FleetLedger;
use App\Services\CircuitBreakerService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

class BackupsOverview extends Component
{
    use WithSiteAuthorization, WithSorting, WithTableFilters, WithVisibleSites;

    protected string $defaultSortBy = 'created_at';

    protected string $defaultSortDir = 'desc';

    /** Per-row site health score, computed once per render and indexed by site_id. */
    #[Computed]
    public function siteHealthScores(): array
    {
        $sites = Site::with('backupConfig')->visibleTo(auth()->user())->get();

        if ($sites->isEmpty()) {
            return [];
        }

        $siteIds = $sites->pluck('id');

        // Batch-load latest completed backup per site
        $latestCompleted = Backup::query()
            ->whereIn('site_id', $siteIds)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->get()
            ->unique('site_id')
            ->keyBy('site_id');

        // Batch-load latest attempt per site
        $latestAttempt = Backup::query()
            ->whereIn('site_id', $siteIds)
            ->orderByDesc('created_at')
            ->get()
            ->unique('site_id')
            ->keyBy('site_id');

        // Sites that have ever had a backup
        $sitesWithBackups = Backup::whereIn('site_id', $siteIds)
            ->distinct('site_id')
            ->pluck('site_id')
            ->flip();

        $service = app(\App\Services\Backup\BackupHealthService::class);
        $scores = [];

        foreach ($sites as $site) {
            $config = $site->backupConfig;
            $hasEverHadBackup = $sitesWithBackups->has($site->id);

            if (! $config && ! $hasEverHadBackup) {
                $scores[$site->id] = null;

                continue;
            }

            $completed = $latestCompleted->get($site->id);
            $attempt = $latestAttempt->get($site->id);

            $scores[$site->id] = $service->computeScoreFromData($site, $config, $completed, $attempt);
        }

        return $scores;
    }

    /**
     * One row per site, worst first — see {@see FleetLedger} for why this starts from the site
     * list rather than from `backups`.
     */
    #[Computed]
    public function ledger(): Collection
    {
        return app(FleetLedger::class)->rows($this->visibleSiteIds());
    }

    /**
     * The sentence above the ledger.
     *
     * It replaces four stat tiles that between them said "Completed: 101" while client sites had
     * no restore point at all — every number true, none of them the answer.
     *
     * Two facts, not one: a scheduled site with no recent restore point is an incident, while a
     * site with no schedule may be a decision somebody made on purpose. Adding them together
     * produces a number that overstates the emergency and gets ignored for it.
     *
     * @return array{failing: int, scheduled: int, unscheduled: int, total: int}
     */
    #[Computed]
    public function fleetSummary(): array
    {
        $rows = $this->ledger;
        $scheduled = $rows->filter(fn (array $row) => $row['scheduled']);

        return [
            'failing' => $scheduled->filter(fn (array $row) => $row['problem'] !== null)->count(),
            'scheduled' => $scheduled->count(),
            'unscheduled' => $rows->count() - $scheduled->count(),
            'total' => $rows->count(),
        ];
    }

    public function reEnableMonitoring(int $siteId): void
    {
        $site = Site::find($siteId);
        if (! $site) {
            session()->flash('backup-error', 'Site not found.');

            return;
        }

        $this->authorizeSiteModification($site);

        CircuitBreakerService::reEnable($site);
        unset($this->ledger, $this->fleetSummary);

        session()->flash('backup-success', "Monitoring re-enabled for {$site->name}. Backups will resume on the next scheduler tick.");
    }

    /**
     * Cancel an in-flight or pending backup. The running job will detect the
     * status change at its next checkCancelled() and abort. The WP-side
     * prepared file is left to expire naturally (7200s transient TTL); we
     * could call /backup/cleanup here but we don't persist the WP token on
     * the Backup row, so cleanup is best-effort via the next prepare-async
     * which would clear stale state.
     */
    public function cancelBackup(int $backupId): void
    {
        $backup = Backup::with('site')->find($backupId);
        if (! $backup) {
            session()->flash('backup-error', 'Backup not found.');

            return;
        }

        $this->authorizeSiteModification($backup->site);

        if (! in_array($backup->status, [
            \App\Enums\BackupStatus::Pending,
            \App\Enums\BackupStatus::InProgress,
        ], true)) {
            session()->flash('backup-error', "Backup #{$backup->id} is not running.");

            return;
        }

        $backup->update([
            'status' => \App\Enums\BackupStatus::Cancelled,
            'stage' => 'cancelled',
            'progress_message' => 'Cancelled by user',
            'completed_at' => now(),
        ]);

        // Ask the engine to stop rather than only marking the row: the runner
        // finalises it when it reaches the cancelling state. Flipping the row and
        // releasing the old engine's uniqueness lock left a live session running
        // under a screen that said "cancelled".
        $session = \App\Backup\V2\Models\BackupSession::where('backup_id', $backup->id)->first();
        if ($session instanceof \App\Backup\V2\Models\BackupSession) {
            app(\App\Backup\V2\Orchestration\SessionActions::class)->cancel($session);
        }

        session()->flash('backup-success', "Backup #{$backup->id} cancelled.");
    }

    public function backupStaleSite(int $siteId): void
    {
        $site = Site::with('backupConfig.storageDestination')->find($siteId);
        if (! $site) {
            session()->flash('backup-error', 'Site not found.');

            return;
        }

        $this->authorizeSiteModification($site);

        if (! $site->is_connected) {
            session()->flash('backup-error', "{$site->name}: connector not reachable — fix the WordPress plugin connection first.");

            return;
        }

        $config = $site->backupConfig;
        if (! $config || ! $config->is_enabled) {
            session()->flash('backup-error', "{$site->name}: backup is not enabled.");

            return;
        }

        try {
            app(BackupLauncher::class)->launch($site, $config->type ?? 'full', 'manual_stale');
        } catch (\Throwable $e) {
            session()->flash('backup-error', "{$site->name}: {$e->getMessage()}");

            return;
        }

        unset($this->ledger, $this->fleetSummary);
        session()->flash('backup-success', "Backup queued for {$site->name}.");
    }

    public function backupAllSites(): void
    {
        if (auth()->user()->isViewer()) {
            abort(403, 'Viewers cannot modify sites.');
        }

        $rateLimitKey = 'bulk-backup-all:'.auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 3, fn () => true, 3600)) {
            $this->dispatch('notify', type: 'error', message: 'Too many bulk backup requests. Please wait before trying again.');

            return;
        }

        $ids = $this->visibleSiteIds();
        $configs = BackupConfig::whereHas('site')
            ->when($ids !== null, fn ($q) => $q->whereIn('site_id', $ids))
            ->where('is_enabled', true)
            ->with(['site', 'storageDestination'])
            ->get();

        $launcher = app(BackupLauncher::class);
        $stagger = (int) config('backups.stagger_interval_seconds', 180);

        $queued = 0;
        foreach ($configs as $config) {
            /** @var Site|null $site */
            $site = $config->site;
            if (! $site || ! $site->is_connected) {
                continue;
            }

            try {
                // Spread them, for the same reason the scheduler does: thirty sites
                // pulled at once is thirty client servers under load at once.
                $launcher->launch($site, $config->type ?? 'full', 'manual_bulk', $queued * $stagger);
                $queued++;
            } catch (\Throwable $e) {
                // A site with no destination, or over quota, is skipped exactly as
                // it was before — but now it is reported rather than passed over
                // in silence.
                report($e);
            }
        }

        $this->dispatch('notify', type: 'success', message: "Queued backups for {$queued} site(s).");
    }

    public function deleteBackup(int $backupId): void
    {
        $backup = Backup::with('site')->findOrFail($backupId);

        // Deleting a backup is destructive and irreversible: block Viewers and
        // scope to sites the user may modify (Admins pass through canAccessSite).
        $site = $backup->site;
        if (! $site instanceof Site) {
            abort(404, 'Backup has no associated site.');
        }
        $this->authorizeSiteModification($site);

        if ($backup->is_locked) {
            session()->flash('backup-error', 'Cannot delete a locked backup.');

            return;
        }

        // Chain guard (P1-28): deleting a base full still carrying incrementals
        // orphans them — they cannot be restored without their base.
        if ($backup->incrementals()->exists()) {
            session()->flash('backup-error', 'Cannot delete a full backup that has incremental backups. Delete the incrementals first.');

            return;
        }

        // P1-28: remove ALL artifacts (primary + replicas + sidecar + manifest +
        // multipart prefix) across every destination, not just the primary file.
        app(\App\Services\Backup\RetentionService::class)->purge($backup);
        session()->flash('backup-success', 'Backup deleted.');
    }

    public function bulkDelete(array $ids): void
    {
        $user = auth()->user();
        if (! $user || $user->isViewer()) {
            abort(403, 'Viewers cannot delete backups.');
        }

        // Only ever touch backups for sites this user may modify; a Manager
        // with a mixed selection silently skips the ones they can't access.
        $backups = Backup::with('site')
            ->whereIn('id', $ids)
            ->where('is_locked', false)
            ->get()
            ->filter(function (Backup $backup) use ($user) {
                $site = $backup->site;

                return $site instanceof Site && $user->canAccessSite($site);
            });
        $count = 0;
        $retention = app(\App\Services\Backup\RetentionService::class);

        foreach ($backups as $backup) {
            // Skip base fulls that still carry incrementals (chain guard, P1-28).
            if ($backup->incrementals()->exists()) {
                continue;
            }
            // Remove ALL artifacts across every destination (P1-28).
            $retention->purge($backup);
            $count++;
        }

        $skipped = count($ids) - $count;
        $msg = "{$count} backup(s) deleted.";
        if ($skipped > 0) {
            $msg .= " {$skipped} backup(s) skipped (locked, has incrementals, or not accessible).";
        }
        session()->flash('backup-success', $msg);
    }

    public function render()
    {
        // `stale` used to be a tab holding its own site-level table. The ledger above answers that
        // question for every site at once, so the tab is gone — but a bookmarked ?filter=stale must
        // still land on something usable rather than an empty table with no way back.
        if ($this->filter === 'stale') {
            $this->filter = 'all';
        }

        $ids = $this->visibleSiteIds();
        $backups = Backup::query()
            ->whereHas('site')
            ->when($ids !== null, fn ($q) => $q->whereIn('backups.site_id', $ids))
            ->with(['site.backupConfig', 'storageDestination'])
            ->when($this->search, function ($q) {
                $escaped = '%'.$this->escapeLike($this->search).'%';
                $q->whereHas('site', fn ($sq) => $sq->where('name', 'ilike', $escaped)
                    ->orWhere('url', 'ilike', $escaped));
            })
            ->when($this->filter !== 'all', function ($q) {
                if ($this->filter === 'in_progress') {
                    return $q->whereIn('backups.status', ['pending', 'in_progress']);
                }

                return $q->where('backups.status', $this->filter);
            })
            ->join('sites', 'backups.site_id', '=', 'sites.id')
            ->orderBy(
                match (true) {
                    $this->sortBy === 'site' => 'sites.sort_order',
                    in_array($this->sortBy, ['created_at', 'type', 'file_size', 'status']) => "backups.{$this->sortBy}",
                    default => 'sites.sort_order',
                },
                $this->sortDir
            )
            ->select('backups.*')
            ->paginate(25);

        return view('livewire.backups.backups-overview', [
            'backups' => $backups,
        ])->layout('components.layouts.app', ['title' => 'Backups']);
    }
}
