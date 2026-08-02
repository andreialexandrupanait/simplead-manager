<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Backup\V2\Support\BackupV2Gate;
use App\Enums\BackupEngine;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Support\PluginPackage;
use App\Support\Semver;

/**
 * What each site needs before it can run the new backup engine, and the three steps that get it
 * there.
 *
 * Moving a site is not one action: the connector has to be new enough to install plugins at all
 * (2.25.0), the backup engine has to be present and current, and only then does flipping the engine
 * mean anything. Done by hand that is three commands and a version comparison per site, twenty-nine
 * times — which is how a migration ends up half-finished with nobody sure which half.
 *
 * So the readiness question gets a single answer per site, with the reason attached. A row that says
 * "not ready" and nothing else is just a question nobody can act on.
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
     *     engine: BackupEngine,
     *     effective_engine: BackupEngine,
     *     destination: ?string,
     *     scheduled: bool,
     *     ready: bool,
     *     blocked_by: ?string,
     *     steps: list<string>,
     * }
     */
    public function status(Site $site): array
    {
        $config = $site->backupConfig;
        $engine = $config instanceof BackupConfig ? $config->backup_engine : BackupEngine::V1;
        $destination = StorageDestination::resolveForSite($site);

        $connector = $site->connector_version;
        $connectorOk = $connector !== null && $connector !== ''
            && Semver::atLeast($connector, self::MIN_CONNECTOR);

        $plugin = $site->backup_plugin_version;
        $pluginOk = $plugin !== null && $plugin !== ''
            && Semver::atLeast($plugin, PluginPackage::backupEngine()->version());

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
            if ($engine !== BackupEngine::V2) {
                $steps[] = 'engine';
            }
        }

        return [
            'site' => $site,
            'connector' => $connector,
            'connector_ok' => $connectorOk,
            'plugin' => $plugin,
            'plugin_ok' => $pluginOk,
            'engine' => $engine,
            // What the site would ACTUALLY run tonight — the column narrowed by the gate. The two
            // can disagree, and when they do it is the gate that decides, so showing only the column
            // would be showing an intention as though it were a fact.
            'effective_engine' => BackupV2Gate::engineFor($site, $config),
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

        if (in_array('engine', $status['steps'], true)) {
            $this->setEngine($site, BackupEngine::V2);
            $done['engine'] = 'v1 -> v2';
        }

        return [
            'ok' => true,
            'message' => $done === [] ? __('Already on V2.') : implode(' · ', $done),
            'steps' => $done,
        ];
    }

    /**
     * Move one site between engines.
     *
     * Writes the column and nothing else: schedules, history and retention stay exactly as they
     * were, so going back is the same single write in the other direction. That is the whole safety
     * net for a same-day migration — if tonight goes badly on a site, it returns to the old engine
     * at the next minute, with no deploy and no database surgery.
     */
    public function setEngine(Site $site, BackupEngine $engine): void
    {
        $config = $site->backupConfig ?? BackupConfig::firstOrCreate(['site_id' => $site->id]);
        $config->forceFill(['backup_engine' => $engine])->save();
    }
}
