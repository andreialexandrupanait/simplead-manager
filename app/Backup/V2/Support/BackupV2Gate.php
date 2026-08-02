<?php

declare(strict_types=1);

namespace App\Backup\V2\Support;

use App\Enums\BackupEngine;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Services\Backup\BackupV2Settings;

/**
 * Single source of truth for whether the V2 backup ENGINE (not the UI — that is
 * BackupV2Access) may run against a specific site.
 *
 * Two independent conditions must BOTH hold:
 *   1. the master kill-switch config('backup_v2.enabled') is true, AND
 *   2. the site is eligible — either fleet enrolment is on (a setting, changed from the console),
 *      or the site id appears in the config('backup_v2.site_ids') allowlist.
 *
 * Both are fail-closed. Fleet enrolment defaults to false and an EMPTY allowlist means "no site is
 * eligible" — it never means "all sites". So with the default config — enabled=false, no setting
 * written, empty site_ids — allowsSite() is false for every site and no engine code path can touch
 * a real site.
 *
 * Eligibility is permission, not intent: it says a site MAY run the new engine, and the
 * `backup_configs.backup_engine` column says whether it does. The column can only ever narrow what
 * this grants, which is what makes turning the fleet back a single write per site.
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
     *
     * `*` means every site, and it is what makes the fleet manageable from a screen instead of from
     * a deployment. The allowlist was the right shape for a pilot — one site, changed by editing the
     * environment — and the wrong shape the moment enrolling a site became an ordinary decision:
     * every new site needed a deploy, which is exactly how retention spent a year switched off
     * behind a variable nobody could reach from the page where the policy is set.
     *
     * The kill switch is untouched. `BACKUP_ENGINE_V2_ENABLED=false` still stops everything, and
     * putting a concrete list back still narrows the fleet to it — both without a database write.
     * With `*`, enrolment is the `backup_configs.backup_engine` column alone, which the console can
     * change per site and undo per site.
     */
    public static function siteAllowed(int $siteId): bool
    {
        // Fleet enrolment, set from the console, says every site may be enrolled and leaves the
        // choice to the per-site column. It lives in the database rather than the environment
        // because the deploy token this manager holds cannot write environment variables — a switch
        // in `.env` would have been one nobody working in the product could reach.
        if (app(BackupV2Settings::class)->fleetEnrolmentEnabled()) {
            return true;
        }

        /** @var list<string> $ids */
        $ids = array_map('strval', (array) config('backup_v2.site_ids', []));

        if ($ids === []) {
            return false;
        }

        if (in_array('*', $ids, true)) {
            return true;
        }

        return in_array((string) $siteId, $ids, true);
    }

    /**
     * May the V2 engine run against this site? enabled AND site on the allowlist.
     */
    public static function allowsSite(int $siteId): bool
    {
        return self::enabled() && self::siteAllowed($siteId);
    }

    /**
     * Which engine actually runs this site's next backup.
     *
     * Two switches, and the order between them is the whole design: the column
     * on backup_configs declares the intent, this gate grants the permission,
     * and permission is checked last. So flipping the column for a site that is
     * not on the env allowlist does nothing, and removing a site from the
     * allowlist returns it to the old engine at the next minute regardless of
     * what the column says. That is the rollback a pilot needs — one line, no
     * database write, no deploy.
     *
     * Not two sources of truth: the column can only ever narrow what the gate
     * already permits.
     */
    public static function engineFor(Site $site, ?BackupConfig $config = null): BackupEngine
    {
        if (! self::allowsSite((int) $site->id)) {
            return BackupEngine::V1;
        }

        $config ??= $site->backupConfig;

        return $config?->backup_engine === BackupEngine::V2
            ? BackupEngine::V2
            : BackupEngine::V1;
    }
}
