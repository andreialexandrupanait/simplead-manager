<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AppBackup;
use App\Models\AppBackupConfig;
use App\Services\ActivityLogger;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * P1-38: AppBackupCreator refuses to start while any AppBackup row is
 * in_progress (`AppBackupCreator::create()`), but a pcntl timeout kill
 * (CreateAppBackup job timeout 1800s), OOM, or a deploy container-recreate
 * leaves the row stuck in_progress forever — CreateAppBackup::failed() only
 * notifies, it never resets the row, and retention only prunes terminal
 * states. Result: platform self-backup silently wedges until manual DB
 * surgery. This mirrors backups:recover-stuck-restores for app backups.
 *
 * Every stage writes progress/log to the row, so updated_at is a heartbeat.
 * A row silent past STALE_AFTER_MINUTES (> the 1800s job timeout) cannot
 * still be running — mark it failed and alert.
 */
class RecoverStuckAppBackups extends Command
{
    /** Must exceed CreateAppBackup::$timeout (1800s = 30 min) with margin. */
    public const STALE_AFTER_MINUTES = 45;

    protected $signature = 'app-backups:recover-stuck {--dry-run : Report without changing anything}';

    protected $description = 'Fail app backups stuck in_progress past the heartbeat threshold so the queue is not wedged';

    public function handle(): int
    {
        $stale = AppBackup::where('status', 'in_progress')
            ->where('updated_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stuck app backups found.');

            return self::SUCCESS;
        }

        foreach ($stale as $backup) {
            $ageMinutes = (int) $backup->updated_at->diffInMinutes(now());
            $this->warn("AppBackup {$backup->id}: in_progress and silent for {$ageMinutes}m");

            if ($this->option('dry-run')) {
                continue;
            }

            $message = "App backup worker died without cleanup — no progress for {$ageMinutes} minutes (auto-recovered).";

            $backup->update([
                'status' => 'failed',
                'error_message' => $message,
                'completed_at' => now(),
                'duration_seconds' => $backup->started_at ? (int) $backup->started_at->diffInSeconds(now()) : null,
            ]);

            AppBackupConfig::instance()->update(['last_backup_status' => 'failed']);

            Log::warning("RecoverStuckAppBackups: AppBackup {$backup->id} marked failed after {$ageMinutes}m of silence");
            ActivityLogger::appBackupFailed($message);

            NotificationService::notifyAppEvent(
                'app_backup_failed',
                'Application Backup Recovered (Stuck)',
                "A stuck application backup was auto-recovered after {$ageMinutes} minutes of silence — the worker died without cleanup.",
                ['Backup' => "#{$backup->id}", 'Silent for' => "{$ageMinutes}m"],
                'critical',
            );
        }

        return self::SUCCESS;
    }
}
