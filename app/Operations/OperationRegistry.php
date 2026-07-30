<?php

declare(strict_types=1);

namespace App\Operations;

use App\Operations\Contracts\Operation;
use App\Operations\Operations\ApplyMaintenancePlanOperation;
use App\Operations\Operations\ApplySitePresetOperation;
use App\Operations\Operations\CopySiteSettingsOperation;
use App\Operations\Operations\CreateBackupOperation;
use App\Operations\Operations\ManagePluginOperation;
use App\Operations\Operations\ManageThemeOperation;
use App\Operations\Operations\PurgeCloudflareCacheOperation;
use App\Operations\Operations\QueueSafeUpdatesOperation;

/**
 * Maps a stable operation key to its concrete {@see Operation} class and
 * resolves it from the container. Bound as a singleton (see AppServiceProvider)
 * so runtime registrations persist for the request/worker lifetime.
 */
class OperationRegistry
{
    /**
     * SPEC §6.2's operation catalogue. Two of the eleven listed types are
     * deliberately absent rather than forgotten:
     *   - restore: a restore needs a specific backup chosen per site, so there is
     *     no meaningful fleet-wide selection to run it over;
     *   - instant wp-admin login: it hands one browser one session — batching it
     *     across thirty sites means nothing.
     *
     * @var array<string, class-string<Operation>>
     */
    private array $map = [
        'cloudflare.purge' => PurgeCloudflareCacheOperation::class,
        'updates.safe' => QueueSafeUpdatesOperation::class,
        'backup.create' => CreateBackupOperation::class,
        'presets.apply' => ApplySitePresetOperation::class,
        'plan.apply' => ApplyMaintenancePlanOperation::class,
        'plugins.manage' => ManagePluginOperation::class,
        'themes.manage' => ManageThemeOperation::class,
        'settings.copy' => CopySiteSettingsOperation::class,
    ];

    /**
     * Register (or override) an operation key → class mapping.
     *
     * @param  class-string<Operation>  $class
     */
    public function register(string $key, string $class): void
    {
        $this->map[$key] = $class;
    }

    /**
     * Resolve an operation instance by key.
     */
    public function resolve(string $key): Operation
    {
        if (! isset($this->map[$key])) {
            throw new \InvalidArgumentException("Unknown operation key: {$key}");
        }

        return app($this->map[$key]);
    }

    /**
     * @return array<string, class-string<Operation>>
     */
    public function all(): array
    {
        return $this->map;
    }
}
