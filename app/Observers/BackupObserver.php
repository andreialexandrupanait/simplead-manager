<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\BackupStatus;
use App\Jobs\NotifyBackupFailed;
use App\Models\Backup;

/**
 * Belt-and-suspenders alerting: if anything (job, manual edit, admin action,
 * future code path) flips Backup.status to 'failed', dispatch NotifyBackupFailed.
 *
 * Existing job/dispatcher paths already call NotifyBackupFailed::dispatch, so
 * this observer mostly catches the corner cases. NotificationService dedups by
 * (event, site_id) over a 5-minute window, so double-dispatching is harmless.
 */
class BackupObserver
{
    public function updated(Backup $backup): void
    {
        if (! $backup->wasChanged('status')) {
            return;
        }

        $newStatus = $backup->status instanceof BackupStatus ? $backup->status->value : $backup->status;
        if ($newStatus !== BackupStatus::Failed->value) {
            return;
        }

        $original = $backup->getOriginal('status');
        $originalStatus = $original instanceof BackupStatus ? $original->value : $original;
        if ($originalStatus === BackupStatus::Failed->value) {
            return; // already failed before — no transition
        }

        $site = $backup->site;
        if (! $site) {
            return;
        }

        $errorMessage = $backup->error_message ?: 'Backup transitioned to failed (cause not recorded).';
        NotifyBackupFailed::dispatch($site, $backup, $errorMessage);
    }
}
