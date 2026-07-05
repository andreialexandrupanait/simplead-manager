<?php

declare(strict_types=1);

namespace App\Dispatchers;

use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Jobs\NotifyBackupFailed;
use App\Jobs\NotifyRestoreFailed;
use App\Jobs\RestoreBackup;
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
        $this->recoverStuckRestores();

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

        foreach ($configs->values() as $index => $config) {
            try {
                $this->dispatchScheduledBackup($config, delaySeconds: $index * 180);
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
     * - Pending: absolute 45 min only (no heartbeat) — job never started
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

        // Pending: job is queued but never picked up — only use absolute timeout
        $stuckPending = Backup::where('status', BackupStatus::Pending)
            ->where('started_at', '<', now()->subMinutes(45))
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
     * Detect restores whose worker died (deploy, OOM, SIGKILL) and mark them
     * failed. NO auto-retry: a restore died mid-flight may have left the site
     * half-restored — blindly re-running it is the one thing we must not do.
     * The operator gets a critical notification and decides.
     */
    protected function recoverStuckRestores(): void
    {
        // RestoreBackup touches updated_at on every stage via progress
        // reporting; restores have longer quiet phases than backups (large
        // downloads/imports), so 30 min of silence — not 20.
        $stuckInProgress = Backup::where('restore_status', BackupStatus::InProgress)
            ->where('updated_at', '<', now()->subMinutes(30))
            ->with('site')
            ->get();

        // Pending: dispatched but never picked up (queue wedged, lock orphaned).
        $stuckPending = Backup::where('restore_status', BackupStatus::Pending)
            ->where('updated_at', '<', now()->subMinutes(60))
            ->with('site')
            ->get();

        foreach ($stuckInProgress->merge($stuckPending) as $backup) {
            try {
                $wasInProgress = $backup->restore_status === BackupStatus::InProgress;

                $backup->update([
                    'restore_status' => BackupStatus::Failed,
                    'restore_stage' => 'failed',
                    'restore_progress_message' => 'Restore marked failed: worker died or job was never picked up.',
                    'restore_error_message' => 'Stuck restore recovered by dispatcher (no progress for '
                        .($wasInProgress ? '30' : '60').'+ minutes).',
                ]);

                RestoreBackup::releaseUniqueLock($backup->id);
                SiteOperationLock::forceRelease($backup->site_id);

                Log::error("recoverStuckRestores: restore of backup #{$backup->id} (site #{$backup->site_id}) marked failed", [
                    'was_in_progress' => $wasInProgress,
                ]);

                if ($backup->site) {
                    ActivityLogger::restoreFailed($backup->site, 'Restore worker died mid-flight; marked failed by dispatcher.');
                    NotifyRestoreFailed::dispatch(
                        $backup->site,
                        $backup,
                        $wasInProgress
                            ? 'Restore worker died mid-flight (deploy/OOM/kill). The site may be half-restored — verify it now.'
                            : 'Restore was queued but never started; it has been cancelled.'
                    );
                }
            } catch (\Throwable $e) {
                Log::error("recoverStuckRestores: failed to recover restore #{$backup->id}: {$e->getMessage()}", [
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
