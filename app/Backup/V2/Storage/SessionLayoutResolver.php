<?php

declare(strict_types=1);

namespace App\Backup\V2\Storage;

use App\Backup\V2\Models\BackupSession;
use App\Models\Backup;
use App\Models\Site;

/**
 * The one place that decides where a backup's objects live.
 *
 * There used to be four answers to that question. RunBackupSessionJob,
 * RunRestoreSessionJob and PreRestoreSafetyBackup each computed the prefix from
 * `backup_id ?? id`; DeepVerifyCommand used `id` alone and a client id from a
 * command-line default. Three of them agreed by coincidence, and the fourth
 * reported a healthy backup as corrupt because it looked in a place nothing had
 * ever written to.
 *
 * Worse, the agreed formula was not stable. Populating `backup_sessions.backup_id`
 * — which linking a session to its V1 row requires — changes what that formula
 * returns, so every object already written would be orphaned by a column update
 * made for an unrelated reason.
 *
 * So the prefix is decided once, on first use, and stored. After that it is read,
 * never derived. Ids and templates are free to change; the objects stay findable.
 */
final class SessionLayoutResolver
{
    /**
     * The layout for a session, freezing the prefix on first use.
     */
    public static function for(BackupSession $session, ?Site $site = null): ObjectLayout
    {
        if (filled($session->object_prefix)) {
            return ObjectLayout::fromPrefix((string) $session->object_prefix);
        }

        $site ??= $session->site;
        $backup = $session->backup;

        $layout = ObjectLayout::forBackup(
            clientId: $site?->getAttribute('client_id') ?? 0,
            siteId: (int) $session->site_id,
            backupId: (int) ($session->backup_id ?? $session->id),
            siteDomain: (string) ($site?->getAttribute('url') ?? ''),
            // The session's own start time, not the clock's. A run that begins at
            // 23:58 and finishes after midnight belongs to the night it started,
            // and — more to the point — re-resolving mid-run must answer the same
            // prefix every time, or the second half of a backup is written
            // somewhere the first half is not.
            date: ($session->created_at ?? now())->format('Y-m-d'),
            time: ($session->created_at ?? now())->format('H-i-s'),
            type: (string) ($session->type ?? ''),
            // Who asked for it lives on the backup row, not the session. By the
            // time a prefix is frozen the envelope has already created that row,
            // so this is normally present; when it is not, the segment falls back
            // to a neutral word rather than guessing "manual" and labelling a
            // scheduled backup with something untrue.
            trigger: $backup instanceof Backup ? (string) $backup->trigger : '',
        );

        // Written without touching timestamps or firing events: this records
        // where the session already is, which is a fact about it rather than a
        // change to it. saveQuietly() would still bump updated_at, and anything
        // reading that column to decide whether a session is progressing would
        // be told a lie by a read-only lookup.
        $session->object_prefix = $layout->prefix();
        $session->newQuery()->whereKey($session->getKey())->toBase()
            ->update(['object_prefix' => $layout->prefix()]);
        $session->syncOriginalAttribute('object_prefix');

        return $layout;
    }
}
