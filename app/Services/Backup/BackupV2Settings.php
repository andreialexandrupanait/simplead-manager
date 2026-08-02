<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Services\SettingsService;

/**
 * The V2 switches that belong on a screen rather than in a deployment.
 *
 * Restore is the one that matters. It shipped behind `BACKUP_ENGINE_V2_RESTORE_ENABLED`, defaulting
 * to false, which was right while the engine was unproven — but it means that today, with restores
 * demonstrated twice on a client site, nobody but someone with a shell can start one. A capability
 * the product has and the product's users cannot reach is not a capability.
 *
 * Same shape as {@see BackupRetentionSettings}: the environment supplies the starting value, the
 * screen owns it from then on, and the master kill switch (`BACKUP_ENGINE_V2_ENABLED`) still stops
 * everything from the deployment side without waiting for a page load.
 */
class BackupV2Settings
{
    public const KEY_RESTORE_ENABLED = 'backup_v2_restore_enabled';

    public function __construct(private readonly SettingsService $settings) {}

    /**
     * May a V2 restore actually run?
     *
     * The env var is the default, not an override: it decides what this reads before anyone has
     * touched the screen, and is ignored afterwards. A setting that the deployment silently wins
     * over is a setting that lies to whoever changed it.
     */
    public function restoreEnabled(): bool
    {
        return (bool) $this->settings->get(
            self::KEY_RESTORE_ENABLED,
            (bool) config('backup_v2.restore_enabled', false),
        );
    }

    public function setRestoreEnabled(bool $enabled): void
    {
        $this->settings->set(self::KEY_RESTORE_ENABLED, $enabled, 'backups', 'boolean');
    }
}
