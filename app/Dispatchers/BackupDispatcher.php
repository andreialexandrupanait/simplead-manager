<?php

declare(strict_types=1);

namespace App\Dispatchers;

use App\Enums\BackupStatus;
use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Models\Backup;
use App\Models\BackupConfig;
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
            });
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
     * Mark in_progress backups older than 35 minutes as failed.
     * These are likely jobs that were killed mid-execution (e.g. container restart).
     */
    protected function recoverStuckBackups(): void
    {
        $stuck = Backup::where('status', BackupStatus::InProgress)
            ->where('started_at', '<', now()->subMinutes(35))
            ->get();

        foreach ($stuck as $backup) {
            Log::warning("Recovering stuck backup #{$backup->id} for site #{$backup->site_id} (started {$backup->started_at})");
            $backup->update([
                'status' => BackupStatus::Failed,
                'stage' => 'failed',
                'progress_message' => 'Backup timed out (stuck recovery)',
                'error_message' => 'Backup was in progress for over 35 minutes and appears stuck. It may have been interrupted by a server restart.',
                'completed_at' => now(),
                'duration_seconds' => $backup->started_at ? (int) $backup->started_at->diffInSeconds(now()) : null,
            ]);

            CreateBackup::releaseUniqueLock($backup->site_id);
            CreateIncrementalBackup::releaseUniqueLock($backup->site_id);
        }
    }
}
