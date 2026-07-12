<?php

declare(strict_types=1);

namespace App\Dispatchers;

use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Jobs\NotifyBackupFailed;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Services\ActivityLogger;
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

        // Calculate next backup time in the config's timezone, then convert to UTC
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

        $config->update(['next_backup_at' => $next->utc()]);
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

        // InProgress: worker should be updating updated_at regularly via
        // reportProgress(). If no update in 20 min, worker is dead.
        $stuckInProgress = Backup::where('status', BackupStatus::InProgress)
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
}
