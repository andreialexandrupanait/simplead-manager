<?php

declare(strict_types=1);

namespace App\Backup\V2\Support;

use App\Models\User;

/**
 * Single source of truth for who may see / use the V2 backup console (P5 UI).
 *
 * Gating rule: the console is reachable only when config('backup_v2.ui_enabled')
 * is true AND the viewer is an admin. With the default flags (all false) every
 * V2 route 404s and no V2 UI is rendered — the console is completely invisible in
 * production and has zero blast radius on the live V1 backup UI.
 */
final class BackupV2Access
{
    /**
     * Master UI flag — is the V2 console exposed at all?
     */
    public static function uiEnabled(): bool
    {
        return (bool) config('backup_v2.ui_enabled', false);
    }

    /**
     * May this user reach the V2 console? Flag on AND admin.
     */
    public static function allows(?User $user): bool
    {
        if (! self::uiEnabled()) {
            return false;
        }

        return $user instanceof User && $user->isAdmin();
    }
}
