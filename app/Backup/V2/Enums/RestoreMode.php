<?php

declare(strict_types=1);

namespace App\Backup\V2\Enums;

/**
 * How a V2 restore reconciles the backup against the live site.
 *
 *  - SafeMerge — overlay the backup's files onto the live tree; NEVER delete a live file
 *                that is absent from the backup. Non-destructive; no pre-restore backup
 *                strictly required. The default.
 *  - Mirror    — reproduce the backup EXACTLY: within the restored scope, live files that
 *                are absent from the backup are deleted (tombstones applied). Destructive,
 *                so it REQUIRES a pre-restore safety backup and explicit confirmation.
 */
enum RestoreMode: string
{
    case SafeMerge = 'safe_merge';
    case Mirror = 'mirror';

    /** Mirror removes live-only files → a pre-restore safety backup is mandatory. */
    public function requiresPreRestoreBackup(): bool
    {
        return $this === self::Mirror;
    }

    public function isDestructive(): bool
    {
        return $this === self::Mirror;
    }
}
