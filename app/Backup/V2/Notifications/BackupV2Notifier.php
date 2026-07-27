<?php

declare(strict_types=1);

namespace App\Backup\V2\Notifications;

use App\Backup\V2\Models\BackupSession;
use App\Mail\Backup\V2\BackupV2SuccessMail;
use App\Mail\Backup\V2\StorageQuotaReachedMail;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Support\Facades\Mail;

/**
 * V2 backup alerts (P5) — backup success + storage-limit-reached.
 *
 * These are NEW classes that sit alongside (never replace) the V1 backup alert
 * stack (App\Mail\BackupAlertMail + App\Jobs\Notify*), so V1 notifications are
 * untouched. Every send is gated by config('backup_v2.notifications.enabled')
 * (default false) → nothing is ever sent in a production build that has not opted
 * into V2.
 *
 * Recipients come from config('backup_v2.notifications.recipients'); when none are
 * configured we fall back to the app "from" address so an enabled build still
 * reaches an operator.
 */
final class BackupV2Notifier
{
    /**
     * Alert on a completed backup. Returns whether a mail was queued.
     */
    public function backupSucceeded(BackupSession $session): bool
    {
        if (! $this->enabled() || ! (bool) config('backup_v2.notifications.notify_on_success', true)) {
            return false;
        }

        $site = $session->site;
        if (! $site instanceof Site) {
            return false;
        }

        $recipients = $this->recipients();
        if ($recipients === []) {
            return false;
        }

        Mail::to($recipients)->send(new BackupV2SuccessMail($site, $session));

        return true;
    }

    /**
     * Alert that a storage destination has reached its quota / warning threshold.
     * Returns whether a mail was queued.
     */
    public function storageQuotaReached(StorageDestination $destination): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        $recipients = $this->recipients();
        if ($recipients === []) {
            return false;
        }

        Mail::to($recipients)->send(new StorageQuotaReachedMail($destination));

        return true;
    }

    private function enabled(): bool
    {
        return (bool) config('backup_v2.notifications.enabled', false);
    }

    /**
     * @return list<string>
     */
    private function recipients(): array
    {
        $configured = (array) config('backup_v2.notifications.recipients', []);
        $configured = array_values(array_filter(array_map('strval', $configured), static fn ($v) => $v !== ''));

        if ($configured !== []) {
            return $configured;
        }

        $from = config('mail.from.address');

        return is_string($from) && $from !== '' ? [$from] : [];
    }
}
