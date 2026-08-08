<?php

declare(strict_types=1);

namespace App\Dispatchers;

use App\Backup\V2\Chain\ChainPlanner;
use App\Backup\V2\Orchestration\SessionActions;
use App\Backup\V2\Support\BackupV2Gate;
use App\Enums\BackupEngine;
use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Jobs\NotifyBackupFailed;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Services\ActivityLogger;
use App\Services\Backup\DiskSpaceGuard;
use App\Services\Backup\SiteOperationLock;
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
        $this->recoverStuckBackups();

        // Stuck-restore recovery is intentionally NOT done here. A healthy
        // restore is legitimately row-silent for up to 30 min inside a single
        // sendRestoreData() HTTP call, so a minute-cadence dispatcher sweep
        // would false-fail a live restore and blind-release the site lock under
        // it (P0-05). The single recovery path is the ownership-checked,
        // 75-min-threshold `backups:recover-stuck-restores` command (PR #38),
        // scheduled in routes/console.php.

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
        $backupType = $this->determineBackupType($config);

        /** @var \App\Models\Site $site */
        $site = $config->site;

        if ($delaySeconds > 0) {
            Log::info("BackupDispatcher: staggering site #{$site->id} ({$site->domain}) by {$delaySeconds}s");
        }

        // The schedule stays here — frequency, hour, which day is the full — and
        // only the engine that carries it out changes. Branching per config in
        // the loop rather than filtering the selection query keeps that query
        // identical for the sites still on the old engine, which is what makes
        // "nothing changed for them" something you can read rather than trust.
        if (BackupV2Gate::engineFor($site, $config) === BackupEngine::V2) {
            $this->dispatchV2Backup($site, $config, $delaySeconds);
            $this->scheduleNextRun($config);

            return;
        }

        if ($backupType === 'incremental') {
            $pending = CreateIncrementalBackup::dispatch(
                $site,
                'scheduled',
                $config->storage_destination_id
            );
            if ($delaySeconds > 0) {
                $pending->delay(now()->addSeconds($delaySeconds));
            }
        } else {
            $pending = CreateBackup::dispatch(
                $site,
                $backupType,
                'scheduled',
                $config->storage_destination_id
            );
            if ($delaySeconds > 0) {
                $pending->delay(now()->addSeconds($delaySeconds));
            }
        }

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
        $next = match ($config->frequency) {
            'daily' => now($tz)->addDay(),
            'weekly' => now($tz)->addWeek(),
            'monthly' => now($tz)->addMonth(),
            default => now($tz)->addDay(),
        };

        if ($config->time) {
            [$hour, $minute] = explode(':', $config->time);
            $next->setTime((int) $hour, (int) $minute);
        }

        $config->update(['next_backup_at' => BackupConfig::asStoredRunTime($next)]);
    }

    /**
     * Determine the backup type based on incremental schedule configuration.
     *
     * Logic:
     * - If incremental_frequency is null → use config type (backwards compatible)
     * - If type is 'database' → always 'database'
     * - If today is full_backup_day_of_week → 'full'
     * - If never had a full backup → 'full'
     * - If last full backup >30 days ago → 'full' (safety)
     * - Otherwise → 'incremental'
     */
    public function determineBackupType(BackupConfig $config): string
    {
        // No incremental enabled — backwards compatible
        if (! $config->incremental_frequency) {
            return $config->type;
        }

        // Database-only configs always stay database
        if ($config->type === 'database') {
            return 'database';
        }

        // If today matches the full backup day of week → full
        if ($config->full_backup_day_of_week !== null) {
            if (now()->dayOfWeek === $config->full_backup_day_of_week) {
                return 'full';
            }
        }

        // Never had a full backup → must do full first
        if (! $config->last_full_backup_at) {
            return 'full';
        }

        // Safety: force full if last full is >30 days old
        if ($config->last_full_backup_at->diffInDays(now()) > 30) {
            return 'full';
        }

        return 'incremental';
    }

    /**
     * Detect stuck backups and auto-retry or mark as failed.
     *
     * Separate detection for InProgress vs Pending to avoid false positives:
     * - InProgress: heartbeat-only — updated_at < 20 min ago means the worker
     *   stopped reporting (was killed, OOM, network died). No absolute timeout
     *   because legitimate WP-side builds on big sites can take 60+ min and
     *   would otherwise be wrongly killed mid-flight. The push pipeline's
     *   pollPrepareStatus + S3 multipart callbacks both touch updated_at on
     *   every event, so a healthy job is always fresh.
     * - Pending: absolute threshold (no heartbeat) — job never started. P2-31:
     *   the threshold must be STAGGER-AWARE. Scheduled/bulk dispatch spaces
     *   jobs by stagger_interval_seconds per site, so the Nth pending backup is
     *   not even expected to start until (N-1) × interval after it was queued.
     *   A fixed 45-min threshold flagged everything past ~15 sites as "stuck"
     *   and spuriously auto-retried it before it ran. We extend the base by the
     *   stagger spread of the whole pending cohort, so no pending backup is
     *   ever considered stale before its own expected start + base threshold.
     *
     * Auto-retries up to 2 times before marking as permanently failed.
     */
    protected function recoverStuckBackups(): void
    {
        $maxAutoRetries = 2;

        // V1 rows only, and this filter is load-bearing. Recovery "recovers" a
        // backup by dispatching CreateBackup — so without it, any V2 session
        // running longer than 20 minutes (which is most of them: it uploads
        // chunk by chunk and does not touch this row between phases) would get
        // the old engine dispatched on top of a live one, both writing to the
        // same client site. V2 sessions are recovered by their own sweep, which
        // reads heartbeat_at and knows how to resume from a checkpoint.
        //
        // Safe to add because every existing row is 'v1' by column default —
        // pinned by EngineColumnTest, since a filter that quietly matched
        // nothing would silently stop recovering V1 backups instead.
        $stuckInProgress = Backup::where('status', BackupStatus::InProgress)
            ->where('engine', BackupEngine::V1)
            ->where('updated_at', '<', now()->subMinutes(20))
            ->with('site')
            ->get();

        // Pending: job is queued but never picked up — absolute threshold,
        // extended by the stagger spread of the pending cohort (P2-31).
        $basePendingMinutes = (int) config('backups.pending_stale_minutes', 45);
        $staggerInterval = (int) config('backups.stagger_interval_seconds', 180);
        $pendingCount = Backup::where('status', BackupStatus::Pending)->count();
        $staggerAllowanceMinutes = (int) ceil(($pendingCount * $staggerInterval) / 60);
        $pendingThresholdMinutes = $basePendingMinutes + $staggerAllowanceMinutes;

        $stuckPending = Backup::where('status', BackupStatus::Pending)
            ->where('engine', BackupEngine::V1)
            ->where('started_at', '<', now()->subMinutes($pendingThresholdMinutes))
            ->with('site')
            ->get();

        $stuck = $stuckInProgress->merge($stuckPending);

        foreach ($stuck as $backup) {
            try {
                if ($backup->auto_retry_count < $maxAutoRetries) {
                    $this->autoRetryBackup($backup);
                } else {
                    $this->markBackupFailed($backup);
                }
            } catch (\Throwable $e) {
                Log::error("recoverStuckBackups: failed to recover backup #{$backup->id}: {$e->getMessage()}", [
                    'backup_id' => $backup->id,
                    'site_id' => $backup->site_id,
                    'exception' => $e::class,
                ]);
            }
        }
    }

    /**
     * Auto-retry a stuck backup by resetting it and dispatching a fresh job.
     */
    protected function autoRetryBackup(Backup $backup): void
    {
        $attempt = $backup->auto_retry_count + 1;

        Log::warning("Auto-retrying stuck backup #{$backup->id} for site #{$backup->site_id} (attempt {$attempt})", [
            'started_at' => $backup->started_at,
            'updated_at' => $backup->updated_at,
            'stage' => $backup->stage,
            'auto_retry_count' => $backup->auto_retry_count,
        ]);

        $backup->update([
            'status' => BackupStatus::Pending,
            'stage' => 'queued',
            'progress_percent' => 0,
            'progress_message' => "Auto-retrying (attempt {$attempt})...",
            'error_message' => null,
            'auto_retry_count' => $attempt,
            'started_at' => now(),
            'completed_at' => null,
            'duration_seconds' => null,
        ]);

        CreateBackup::releaseUniqueLock($backup->site_id);
        CreateIncrementalBackup::releaseUniqueLock($backup->site_id);
        $this->releaseSiteLockHeldByBackupJob($backup);

        $site = $backup->site;
        if (! $site) {
            Log::error("Auto-retry: site not found for backup #{$backup->id}");
            $this->markBackupFailed($backup);

            return;
        }

        if ($backup->type === 'incremental') {
            CreateIncrementalBackup::dispatch(
                $site,
                $backup->trigger,
                $backup->storage_destination_id,
                $backup->id,
            );
        } else {
            CreateBackup::dispatch(
                $site,
                $backup->type,
                $backup->trigger,
                $backup->storage_destination_id,
                $backup->id,
            );
        }
    }

    /**
     * Mark a stuck backup as permanently failed (auto-retries exhausted).
     */
    protected function markBackupFailed(Backup $backup): void
    {
        $retryInfo = $backup->auto_retry_count > 0
            ? " Auto-retried {$backup->auto_retry_count} time(s)."
            : '';

        Log::warning("Marking stuck backup #{$backup->id} as failed for site #{$backup->site_id} (started {$backup->started_at}).{$retryInfo}");

        $errorMessage = "Backup appears stuck and could not be recovered.{$retryInfo} It may have been interrupted by a server restart.";

        $backup->update([
            'status' => BackupStatus::Failed,
            'stage' => 'failed',
            'progress_message' => 'Backup timed out (stuck recovery)',
            'error_message' => $errorMessage,
            'completed_at' => now(),
            'duration_seconds' => $backup->started_at ? (int) $backup->started_at->diffInSeconds(now()) : null,
        ]);

        CreateBackup::releaseUniqueLock($backup->site_id);
        CreateIncrementalBackup::releaseUniqueLock($backup->site_id);
        $this->releaseSiteLockHeldByBackupJob($backup);

        $site = $backup->site;
        if ($site) {
            $site->update(['backup_ok' => false]);
            $config = $site->backupConfig;
            if ($config) {
                $config->update(['last_backup_status' => 'failed']);
            }
            NotifyBackupFailed::dispatch($site, $backup, $errorMessage);
            ActivityLogger::backupFailed($site, $errorMessage);
        }
    }

    /**
     * Hand back the site lock a dead backup job was holding.
     *
     * Stuck recovery released the jobs' unique locks and nothing else, so a
     * worker killed mid-backup left SiteOperationLock held for its full two-hour
     * TTL. During that window the site took no backup, no restore and no safe
     * update from anything — recovery would dutifully re-dispatch, the fresh job
     * would fail to acquire, and the site sat idle until the lock aged out. The
     * one mechanism meant to unstick a site was the one thing that could not.
     *
     * Released by owner match, never blind force: the holder may be a restore or
     * a safe update that is running perfectly well, and taking its lock away
     * would let a backup start on top of it. Same prefix convention the jobs
     * themselves use in BackupJobTrait::failed().
     */
    protected function releaseSiteLockHeldByBackupJob(Backup $backup): void
    {
        $holder = SiteOperationLock::current($backup->site_id);
        if ($holder === null) {
            return;
        }

        $ref = $holder['ref'];
        $ours = str_starts_with($ref, CreateBackup::class.':')
            || str_starts_with($ref, CreateIncrementalBackup::class.':');

        if (! $ours) {
            Log::info("Stuck backup #{$backup->id}: site lock held by {$ref} — left alone", [
                'site_id' => $backup->site_id,
                'operation' => $holder['operation'],
            ]);

            return;
        }

        SiteOperationLock::forceRelease($backup->site_id);
        Log::warning("Released the site lock held by a dead backup job ({$ref})", [
            'site_id' => $backup->site_id,
            'backup_id' => $backup->id,
        ]);
    }
}
