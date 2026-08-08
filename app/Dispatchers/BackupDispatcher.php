<?php

declare(strict_types=1);

namespace App\Dispatchers;

use App\Backup\V2\Chain\ChainPlanner;
use App\Backup\V2\Orchestration\SessionActions;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Services\Backup\DiskSpaceGuard;
use App\Services\CircuitBreakerService;
use Illuminate\Support\Facades\Log;

class BackupDispatcher
{
    /**
     * Dispatch due backup jobs.
     * Called every minute from the scheduler.
     */
    public function __invoke(): void
    {
        // Stuck backups are recovered by `backups:recover-stuck-sessions`, which
        // reads heartbeat_at and resumes from a checkpoint. The sweep that used to
        // live here "recovered" a backup by dispatching the old engine's job, and
        // was filtered to V1 rows for exactly that reason — with the old engine
        // gone, so is the reason for it.
        //
        // Restores are likewise not swept from here: a minute-cadence sweep would
        // false-fail a live one and blind-release the site lock under it (P0-05).
        // Recovery is `backups:recover-stuck-sessions`, which checks ownership
        // and a heartbeat before it touches anything.

        CircuitBreakerService::checkHalfOpen();

        if (! app(DiskSpaceGuard::class)->canDispatchBackup()) {
            return;
        }

        $configs = BackupConfig::query()
            ->where('is_enabled', true)
            ->where('next_backup_at', '<=', now())
            ->whereHas('site', fn ($q) => $q
                ->whereNull('deleted_at')
                ->where('is_connected', true)
                ->where(fn ($sq) => $sq
                    ->whereDoesntHave('healthState')
                    ->orWhereHas('healthState', fn ($hq) => $hq->where('is_monitoring_disabled', false))
                )
            )
            ->whereDoesntHave('site.backups', fn ($q) => $q
                ->whereIn('status', [BackupStatus::Pending, BackupStatus::InProgress])
            )
            // Never dispatch a scheduled backup while a restore is running on
            // the same site — the two would interleave on the live tree.
            ->whereDoesntHave('site.backups', fn ($q) => $q
                ->whereIn('restore_status', [BackupStatus::Pending, BackupStatus::InProgress])
            )
            ->with('site')
            ->get();

        $staggerInterval = (int) config('backups.stagger_interval_seconds', 180);

        foreach ($configs->values() as $index => $config) {
            try {
                $this->dispatchScheduledBackup($config, delaySeconds: $index * $staggerInterval);
            } catch (\Throwable $e) {
                Log::error("BackupDispatcher: failed to dispatch backup for site #{$config->site_id}: {$e->getMessage()}", [
                    'config_id' => $config->id,
                    'site_id' => $config->site_id,
                    'exception' => $e::class,
                ]);
            }
        }
    }

    protected function dispatchScheduledBackup(BackupConfig $config, int $delaySeconds = 0): void
    {
        /** @var \App\Models\Site $site */
        $site = $config->site;

        if ($delaySeconds > 0) {
            Log::info("BackupDispatcher: staggering site #{$site->id} ({$site->domain}) by {$delaySeconds}s");
        }

        $this->dispatchV2Backup($site, $config, $delaySeconds);
        $this->scheduleNextRun($config);
    }

    /**
     * Hand a scheduled run to the new engine.
     *
     * The type is decided by ChainPlanner rather than determineBackupType()
     * because the chain is a property of the sessions, not of the config: it has
     * to know which completed full to build on and what position this link takes.
     * The user-facing schedule is the same two fields either way.
     *
     * Except for one, which this used to drop. A database-only schedule is not a
     * chain decision at all — it is a scope decision, and ChainPlanner only ever
     * answers full or incremental. So a config saved as `type = 'database'` was
     * honoured by determineBackupType() on the old engine and silently ignored
     * here, and the site got a full every night while its schedule screen said
     * otherwise. Scope is settled first, and only then is the chain consulted.
     */
    protected function dispatchV2Backup(\App\Models\Site $site, BackupConfig $config, int $delaySeconds): void
    {
        if ($config->type === 'database') {
            $plan = ['type' => 'database', 'full_base_id' => null, 'chain_position' => null];
        } else {
            $plan = (new ChainPlanner)->planFor($site);
        }

        try {
            app(SessionActions::class)->startBackup($site, $plan['type'], [
                'trigger' => 'scheduled',
                'full_base_id' => $plan['full_base_id'],
                'chain_position' => $plan['chain_position'],
                'delay_seconds' => $delaySeconds,
            ]);
        } catch (\Throwable $e) {
            Log::error("BackupDispatcher: could not start a backup for site #{$site->id}: {$e->getMessage()}", [
                'site_id' => $site->id,
                'exception' => $e::class,
            ]);
        }
    }

    /**
     * Calculate the next run in the config's timezone, then store it the way the column is read.
     *
     * This used to store `$next->utc()`, which is the one thing it must not do: the selection above
     * compares the column against `now()` in the application's timezone, so a UTC wall clock was
     * being read on a Europe/Bucharest one and every site in that zone ran three hours early. See
     * {@see BackupConfig::asStoredRunTime()}.
     */
    protected function scheduleNextRun(BackupConfig $config): void
    {
        $tz = $config->timezone ?: 'UTC';
        $now = now($tz);

        [$hour, $minute] = $config->time
            ? array_map('intval', explode(':', $config->time))
            : [0, 0];

        // The day the person chose, not seven days from whenever the last run
        // happened to land.
        //
        // This did `addWeek()` and `addMonth()` from now, so a schedule set to
        // Monday drifted to whatever weekday the previous run fell on, and a
        // monthly one to whatever date. The form has offered both fields the
        // whole time; nothing downstream read them. A setting that does not
        // change the behaviour it names is worse than one that is missing, because
        // the screen goes on reporting it back to you.
        $next = match ($config->frequency) {
            'weekly' => $this->nextWeekday($now, (int) ($config->day_of_week ?? $now->dayOfWeek), $hour, $minute),
            'monthly' => $this->nextMonthDay($now, (int) ($config->day_of_month ?? $now->day), $hour, $minute),
            default => $now->copy()->addDay()->setTime($hour, $minute),
        };

        $config->update(['next_backup_at' => BackupConfig::asStoredRunTime($next)]);
    }

    /** The next occurrence of this weekday at this hour, always in the future. */
    private function nextWeekday(\Illuminate\Support\Carbon $now, int $dayOfWeek, int $hour, int $minute): \Illuminate\Support\Carbon
    {
        $next = $now->copy()->setTime($hour, $minute);

        // A run that has just finished must not schedule itself for today, or the
        // dispatcher picks it straight back up.
        while ($next->dayOfWeek !== $dayOfWeek || $next->lessThanOrEqualTo($now)) {
            $next->addDay();
        }

        return $next;
    }

    /**
     * The next occurrence of this day-of-month at this hour.
     *
     * Clamped to the length of the target month, so the 31st in a 30-day month is
     * the 30th rather than silently rolling into the next one — which is how a
     * monthly backup set for the 31st would have run in January, March, May and
     * skipped everything else.
     */
    private function nextMonthDay(\Illuminate\Support\Carbon $now, int $dayOfMonth, int $hour, int $minute): \Illuminate\Support\Carbon
    {
        $candidate = $now->copy()->startOfMonth();

        for ($i = 0; $i < 2; $i++) {
            $day = min($dayOfMonth, $candidate->daysInMonth);
            $next = $candidate->copy()->setDay($day)->setTime($hour, $minute);

            if ($next->greaterThan($now)) {
                return $next;
            }

            $candidate->addMonthNoOverflow();
        }

        return $candidate->setDay(min($dayOfMonth, $candidate->daysInMonth))->setTime($hour, $minute);
    }
}
