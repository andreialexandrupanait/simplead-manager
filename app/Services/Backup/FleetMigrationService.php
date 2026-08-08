<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Support\PluginPackage;
use App\Support\Semver;

/**
 * What a site needs before it can be backed up at all, and the steps that get it there.
 *
 * Preparing a site is not one action: the connector has to be new enough to install plugins at all
 * (2.25.0), the backup engine has to be present, current and answering, and the site needs a config
 * row to carry its schedule. Done by hand that is several commands and a version comparison per site,
 * thirty times — which is how a rollout ends up half-finished with nobody sure which half.
 *
 * So the readiness question gets a single answer per site, with the reason attached. A row that says
 * "not ready" and nothing else is just a question nobody can act on.
 *
 * There used to be a fourth step: flipping a `backup_configs.backup_engine` roster that chose between
 * two engines. With one engine there is nothing to choose, and the roster had become a trap — it
 * defaults to 'v1' and the plugin installer filtered on it, so a newly connected site would have been
 * skipped in silence and then shown a backup screen that never backed anything up.
 */
class FleetMigrationService
{
    /** The connector release that added /plugins/install-package. */
    public const MIN_CONNECTOR = '2.25.0';

    public function __construct(
        private readonly BackupPluginInstaller $installer,
    ) {}

    /**
     * One row of the migration console.
     *
     * @return array{
     *     site: Site,
     *     connector: ?string,
     *     connector_ok: bool,
     *     plugin: ?string,
     *     plugin_ok: bool,
     *     destination: ?string,
     *     scheduled: bool,
     *     answering: bool,
     *     engine_silent: bool,
     *     engine_error: ?string,
     *     checked_at: ?\Illuminate\Support\Carbon,
     *     ready: bool,
     *     blocked_by: ?string,
     *     steps: list<string>,
     * }
     */
    public function status(Site $site): array
    {
        $config = $site->backupConfig;
        $destination = StorageDestination::resolveForSite($site);

        $connector = $site->connector_version;
        $connectorOk = $connector !== null && $connector !== ''
            && Semver::atLeast($connector, self::MIN_CONNECTOR);

        // What the engine SAID when we last asked it, not what an install told us it would be.
        // Those are different claims, and treating the second as the first is how thirteen sites
        // came to be recorded as migrated while answering 401 to every request.
        $plugin = $site->backup_plugin_version;
        $answering = $plugin !== null && $plugin !== '';
        $pluginOk = $answering && Semver::atLeast($plugin, PluginPackage::backupEngine()->version());

        $blocked = match (true) {
            ! $site->is_connected => __('The connector is not reachable on this site.'),
            $destination === null => __('No storage destination is configured.'),
            // Storage that this engine cannot write to is a blocker rather than a step: it is a
            // decision about where the client's data lives, not something to fix in passing.
            ! in_array($destination->type, ['s3', 'b2', 'hetzner_objectstorage'], true) => __('This site stores backups on :type, which the V2 engine does not write to.', ['type' => $destination->type]),
            default => null,
        };

        $steps = [];
        if ($blocked === null) {
            if (! $connectorOk) {
                $steps[] = 'connector';
            }
            if (! $pluginOk) {
                $steps[] = 'plugin';
            }
        }

        // A site we have asked, and which said nothing back.
        //
        // Kept as its own state rather than folded into `plugin_ok`, because "we put it there" and
        // "it answers" are different claims — treating the first as the second is how thirteen sites
        // came to be recorded as migrated while answering 401 to every request. It cannot be derived
        // from `plugin_ok`, which already requires `answering`: that pairing is always false, which
        // is what the check below would have quietly become.
        $engineSilent = $site->backup_plugin_checked_at !== null && ! $answering;

        return [
            'site' => $site,
            'connector' => $connector,
            'connector_ok' => $connectorOk,
            'plugin' => $plugin,
            'plugin_ok' => $pluginOk,
            'answering' => $answering,
            'engine_silent' => $engineSilent,
            'engine_error' => $site->backup_plugin_error,
            'checked_at' => $site->backup_plugin_checked_at,
            'destination' => $destination?->name,
            'scheduled' => (bool) $config?->is_enabled,
            'ready' => $blocked === null,
            'blocked_by' => $blocked,
            'steps' => $steps,
        ];
    }

    /**
     * Do whatever this site still needs, in order, stopping at the first step that fails.
     *
     * Stopping matters: installing the engine on a site whose connector is too old fails anyway, and
     * flipping the engine column for a site with no engine installed would schedule a backup that
     * cannot run. Each step is only attempted once its predecessor is true.
     *
     * @return array{ok: bool, message: string, steps: array<string, string>}
     */
    public function migrate(Site $site, ConnectorPusher $connector): array
    {
        $status = $this->status($site);

        if (! $status['ready']) {
            return ['ok' => false, 'message' => (string) $status['blocked_by'], 'steps' => []];
        }

        $done = [];

        if (in_array('connector', $status['steps'], true)) {
            $result = $connector->push($site);
            $done['connector'] = $result['message'];
            if (! $result['ok']) {
                return ['ok' => false, 'message' => __('Connector update failed: ').$result['message'], 'steps' => $done];
            }
        }

        if (in_array('plugin', $status['steps'], true)) {
            $result = $this->installer->install($site);
            $done['plugin'] = $result['message'];
            if (! $result['ok']) {
                return ['ok' => false, 'message' => __('Backup engine install failed: ').$result['message'], 'steps' => $done];
            }
        }

        // A site with no config row at all gets one here. `backup_configs` is only ever created by
        // MaintenancePlanService, and only for a site whose plan carries an enabled backup module, so
        // a site without a plan has no row — and a site with no row has no schedule, no retention and
        // no destination, however installed its engine is.
        if ($site->backupConfig === null) {
            $this->makeConfigForSite($site);
            $done['config'] = __('Backup settings created.');
        }

        return [
            'ok' => true,
            'message' => $done === [] ? __('Already set up.') : implode(' · ', $done),
            'steps' => $done,
        ];
    }

    /**
     * The config row for a site that never got one, on the fleet's defaults rather than the schema's.
     *
     * `backup_configs` is only ever created automatically by MaintenancePlanService, and only for a
     * site whose plan carries an enabled backup module. A site with no plan therefore has no row at
     * all — which is why its onboarding silently skips the first backup. Creating it here on the
     * schema defaults would leave `UTC` for the timezone, a retention of 10 rather than the fleet's
     * 30, and no destination: a config that exists, looks configured, and is wrong in three places
     * nobody would think to check.
     *
     * `is_enabled` deliberately stays false. Starting a nightly schedule spends storage on a client's
     * behalf, so it remains an explicit decision made on the site's own screen. What this fixes is
     * that when someone does make it, the rest is already right.
     *
     * The timezone is the application's rather than the site's: `sites` has no timezone column, and
     * the fleet's existing rows are almost all Europe/Bucharest, which is what config('app.timezone')
     * holds. Better a right-by-default than a UTC that silently shifts a 03:00 window by three hours.
     */
    private function makeConfigForSite(Site $site): BackupConfig
    {
        $destination = StorageDestination::resolveForSite($site);

        return BackupConfig::create([
            'site_id' => $site->id,
            'is_enabled' => false,
            'frequency' => 'daily',
            'time' => '03:00',
            'timezone' => config('app.timezone'),
            'type' => 'full',
            'retention_type' => 'count',
            'retention_value' => 30,
            'storage_destination_id' => $destination?->id,
        ]);
    }
}
