<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BackupStatus;
use App\Jobs\NotifyRestoreFailed;
use App\Models\Backup;
use App\Services\ActivityLogger;
use App\Services\Backup\SiteOperationLock;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Audit E-23: when a restore worker dies without running failed() (SIGKILL,
 * OOM, container recreate), the backup stays restore_status=in_progress and
 * the site lock stays held for up to its 7200s TTL — and the deliberate
 * re-run guard (E-05) means nothing recovers it without an operator.
 *
 * Every restore stage writes progress to the backup row, so backups.updated_at
 * is a heartbeat. RestoreBackup's timeout is 3600s: an in_progress restore
 * whose row hasn't been touched for STALE_AFTER_MINUTES cannot still be
 * running — mark it failed, release the lock (ownership-checked), and alert.
 */
class RecoverStuckRestores extends Command
{
    /** Must exceed RestoreBackup::$timeout (3600s) with margin. */
    public const STALE_AFTER_MINUTES = 75;

    protected $signature = 'backups:recover-stuck-restores {--dry-run : Report without changing anything}';

    protected $description = 'Fail restores stuck in_progress past the heartbeat threshold and release their site locks';

    public function handle(): int
    {
        $stale = Backup::where('restore_status', BackupStatus::InProgress)
            ->where('updated_at', '<', now()->subMinutes(self::STALE_AFTER_MINUTES))
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stuck restores found.');

            return self::SUCCESS;
        }

        foreach ($stale as $backup) {
            $ageMinutes = (int) $backup->updated_at->diffInMinutes(now());
            $this->warn("Backup {$backup->id} (site {$backup->site_id}): restore silent for {$ageMinutes}m");

            if ($this->option('dry-run')) {
                continue;
            }

            $message = "Restore worker died without cleanup — no progress for {$ageMinutes} minutes (auto-recovered).";

            $backup->update([
                'restore_status' => BackupStatus::Failed,
                'restore_error_message' => $message,
                'restore_progress_message' => $message,
            ]);

            // Ownership-checked release — mirror RestoreBackup::failed(). Never
            // blind-force: a successor operation may legitimately hold the lock.
            $holder = SiteOperationLock::current($backup->site_id);
            if ($holder !== null
                && $holder['operation'] === SiteOperationLock::OPERATION_RESTORE
                && $holder['ref'] === 'backup:'.$backup->id) {
                SiteOperationLock::forceRelease($backup->site_id);
                $this->info("Released site lock for site {$backup->site_id}.");
            }

            Log::warning("RecoverStuckRestores: backup {$backup->id} marked failed after {$ageMinutes}m of silence");

            $site = $backup->site;
            if ($site) {
                ActivityLogger::restoreFailed($site, $message);
                NotifyRestoreFailed::dispatch($site, $backup, $message);
            }
        }

        return self::SUCCESS;
    }
}
