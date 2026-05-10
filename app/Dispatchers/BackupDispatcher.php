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

        CircuitBreakerService::checkHalfOpen();

        if (! app(DiskSpaceGuard::class)->canDispatchBackup()) {
            return;
        }

        BackupConfig::query()
            ->where('is_enabled', true)
            ->where('next_backup_at', '<=', now())
            ->whereHas('site', fn ($q) => $q
                ->whereNull('deleted_at')
                ->where('is_connected', true)
            )
            ->whereHas('site.healthState', fn ($q) => $q
                ->where('circuit_state', '!=', 'open')
                ->where('is_monitoring_disabled', false)
            )
            ->with('site')
            ->each(function (BackupConfig $config) {
                try {
                    $this->dispatchScheduledBackup($config);
                } catch (\Throwable $e) {
                    Log::error("BackupDispatcher: failed to dispatch backup for site #{$config->site_id}: {$e->getMessage()}", [
                        'config_id' => $config->id,
                        'site_id' => $config->site_id,
                        'exception' => $e::class,
                    ]);
                }
            });
    }

    protected function dispatchScheduledBackup(BackupConfig $config): void
    {
        $backupType = $this->determineBackupType($config);

        /** @var \App\Models\Site $site */
        $site = $config->site;

        if ($backupType === 'incremental') {
            CreateIncrementalBackup::dispatch(
                $site,
                'scheduled',
                $config->storage_destination_id
            );
        } else {
            CreateBackup::dispatch(
                $site,
                $backupType,
                'scheduled',
                $config->storage_destination_id
            );
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
     * Detect stuck in_progress backups and auto-retry or mark as failed.
     *
     * Two-tier detection:
     * - Tier 1: No progress update (updated_at) in 10 minutes → worker likely killed
     * - Tier 2: Started over 60 minutes ago → absolute safety net
     *
     * Auto-retries up to 2 times before marking as permanently failed.
     */
    protected function recoverStuckBackups(): void
    {
        $maxAutoRetries = 2;
        $heartbeatMinutes = 10;
        $absoluteMinutes = 60;

        $stuck = Backup::where('status', BackupStatus::InProgress)
            ->where(function ($query) use ($heartbeatMinutes, $absoluteMinutes) {
                $query->where('updated_at', '<', now()->subMinutes($heartbeatMinutes))
                    ->orWhere('started_at', '<', now()->subMinutes($absoluteMinutes));
            })
            ->with('site')
            ->get();

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
