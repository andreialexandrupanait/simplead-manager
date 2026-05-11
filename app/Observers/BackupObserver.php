<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\BackupStatus;
use App\Jobs\NotifyBackupFailed;
use App\Models\Backup;

/**
 * Belt-and-suspenders for terminal Backup transitions.
 *
 * On status → completed: syncs Site.last_backup_at / backup_ok and
 * BackupConfig.last_backup_at / last_backup_status / last_full_backup_at.
 * The CreateBackup job already does this in its finalize methods, but if the
 * job is killed (MaxAttemptsExceeded, OOM, etc.) between marking the Backup
 * row completed and writing the Site/BackupConfig metadata, those fields stay
 * stale forever and the Dashboard reports a false-positive "stale backup".
 * This observer guarantees the metadata gets written whenever a Backup
 * transitions to completed, regardless of which code path got it there.
 *
 * On status → failed: dispatches NotifyBackupFailed for corner cases not
 * already covered by the explicit dispatch in the job/dispatcher.
 * NotificationService dedups by (event, site_id) over 5 minutes, so
 * double-dispatching is harmless.
 *
 * Administrative markers (operator manually flipped status to "failed" to
 * clean up zombie rows or trigger a redispatch) are detected by an
 * error_message prefix and silenced.
 */
class BackupObserver
{
    /**
     * Substrings in error_message that mark this transition as administrative
     * (an operator wrote it on purpose), not a real failure. Case-sensitive.
     */
    private const ADMIN_MARKERS = [
        'Zombie pending: re-dispatched',
        'Superseded by a new backup attempt',
        '[admin]',
    ];

    public function updated(Backup $backup): void
    {
        if (! $backup->wasChanged('status')) {
            return;
        }

        $newStatus = $backup->status instanceof BackupStatus ? $backup->status->value : $backup->status;
        $original = $backup->getOriginal('status');
        $originalStatus = $original instanceof BackupStatus ? $original->value : $original;

        if ($originalStatus === $newStatus) {
            return; // no actual transition
        }

        if ($newStatus === BackupStatus::Completed->value) {
            $this->handleCompleted($backup);

            return;
        }

        if ($newStatus === BackupStatus::Failed->value) {
            $this->handleFailed($backup);
        }
    }

    private function handleCompleted(Backup $backup): void
    {
        $site = $backup->site;
        if (! $site) {
            return;
        }

        $completedAt = $backup->completed_at ?? now();

        if ($site->last_backup_at === null || $site->last_backup_at->lt($completedAt)) {
            $site->update([
                'backup_ok' => true,
                'last_backup_at' => $completedAt,
            ]);
        }

        $config = $site->backupConfig;
        if (! $config) {
            return;
        }

        $configUpdates = [
            'last_backup_status' => 'completed',
        ];

        if ($config->last_backup_at === null || $config->last_backup_at->lt($completedAt)) {
            $configUpdates['last_backup_at'] = $completedAt;
        }

        if ($backup->type === 'full'
            && ($config->last_full_backup_at === null || $config->last_full_backup_at->lt($completedAt))) {
            $configUpdates['last_full_backup_at'] = $completedAt;
        }

        $config->update($configUpdates);
    }

    private function handleFailed(Backup $backup): void
    {
        $errorMessage = $backup->error_message ?? '';
        foreach (self::ADMIN_MARKERS as $marker) {
            if (str_contains($errorMessage, $marker)) {
                return; // administrative bookkeeping, not a real failure
            }
        }

        $site = $backup->site;
        if (! $site) {
            return;
        }

        NotifyBackupFailed::dispatch(
            $site,
            $backup,
            $errorMessage !== '' ? $errorMessage : 'Backup transitioned to failed (cause not recorded).'
        );
    }
}
