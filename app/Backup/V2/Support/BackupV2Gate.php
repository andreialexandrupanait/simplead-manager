<?php

declare(strict_types=1);

namespace App\Backup\V2\Support;

/**
 * Single source of truth for whether the V2 backup ENGINE (not the UI — that is
 * BackupV2Access) may run against a specific site.
 *
 * Two independent conditions must BOTH hold:
 *   1. the master kill-switch config('backup_v2.enabled') is true, AND
 *   2. the site id appears in the config('backup_v2.site_ids') allowlist.
 *
 * The allowlist is fail-closed: an EMPTY list means "no site is eligible" (it never
 * means "all sites"). So with the default config — enabled=false and an empty
 * site_ids — allowsSite() is false for every site and no engine code path can touch
 * a real site. A site is eligible only when the owner has both flipped the master
 * switch AND explicitly listed that site id.
 */
final class BackupV2Gate
{
    /**
     * Master kill-switch — is the V2 engine enabled at all?
     */
    public static function enabled(): bool
    {
        return (bool) config('backup_v2.enabled', false);
    }

    /**
     * Is this site id on the (non-empty) allowlist? Empty allowlist → false.
     */
    public static function siteAllowed(int $siteId): bool
    {
        /** @var list<string> $ids */
        $ids = (array) config('backup_v2.site_ids', []);

        if ($ids === []) {
            return false;
        }

        return in_array((string) $siteId, array_map('strval', $ids), true);
    }

    /**
     * May the V2 engine run against this site? enabled AND site on the allowlist.
     */
    public static function allowsSite(int $siteId): bool
    {
        return self::enabled() && self::siteAllowed($siteId);
    }
}
