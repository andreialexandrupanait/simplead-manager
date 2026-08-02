<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\BackupConfig;
use App\Services\SettingsService;

/**
 * Whether old backups are actually deleted, and how many are kept — as a setting, not an env var.
 *
 * This used to live in `BACKUP_RETENTION_DRY_RUN`, which meant that turning it on required editing
 * the deployment and redeploying. So it was never turned on: retention ran nightly, decided what to
 * remove, logged it, and removed nothing. A terabyte accumulated behind a switch nobody could reach
 * from the screen where the policy is configured.
 *
 * The env var survives as a one-way safety catch — it can force dry-run, never the reverse — so
 * there is still a way to stop deletions without waiting for a UI round trip.
 */
class BackupRetentionSettings
{
    public const KEY_ENABLED = 'backup_retention_deletes_enabled';

    public const KEY_KEEP = 'backup_retention_keep_per_site';

    /** Kept when nothing has been chosen: roughly a month of dailies, counted in restore points. */
    public const DEFAULT_KEEP = 30;

    public const MIN_KEEP = 3;

    public const MAX_KEEP = 365;

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * Are deletions real, or is retention only reporting what it would do?
     *
     * Defaults to false. A system that starts deleting because somebody deployed it is worse than
     * one that starts by telling you what it intends to.
     */
    public function deletesEnabled(): bool
    {
        // One-way safety catch: BACKUP_RETENTION_DRY_RUN can force reporting-only, never the
        // reverse. Nothing set in the deployment can make the system delete more than the screen says.
        if ((bool) config('backups.retention_dry_run', false)) {
            return false;
        }

        return (bool) $this->settings->get(self::KEY_ENABLED, false);
    }

    public function setDeletesEnabled(bool $enabled): void
    {
        $this->settings->set(self::KEY_ENABLED, $enabled, 'backups', 'boolean');
    }

    /**
     * How many restore points each site keeps.
     */
    public function keepPerSite(): int
    {
        $value = (int) $this->settings->get(self::KEY_KEEP, self::DEFAULT_KEEP);

        return max(self::MIN_KEEP, min(self::MAX_KEEP, $value));
    }

    /**
     * Set the fleet-wide count and write it onto every site's schedule.
     *
     * Applied to the sites themselves rather than read at delete time, because the per-site value is
     * what the schedule screen shows and what the report predicts from. Leaving the two to disagree
     * is how you end up explaining why a site kept a different number than the settings page says.
     *
     * @return int how many site schedules changed
     */
    public function setKeepPerSite(int $keep): int
    {
        $keep = max(self::MIN_KEEP, min(self::MAX_KEEP, $keep));
        $this->settings->set(self::KEY_KEEP, $keep, 'backups', 'integer');

        return BackupConfig::query()
            ->where(function ($q) use ($keep) {
                $q->where('retention_type', '!=', 'count')->orWhere('retention_value', '!=', $keep);
            })
            ->update(['retention_type' => 'count', 'retention_value' => $keep]);
    }
}
