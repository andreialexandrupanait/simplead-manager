<?php

declare(strict_types=1);

namespace App\Livewire\Backup\V2;

use App\Backup\V2\Support\BackupV2Access;
use App\Enums\BackupEngine;
use App\Jobs\MigrateSiteToBackupV2;
use App\Models\Site;
use App\Services\Backup\BackupPluginInstaller;
use App\Services\Backup\BackupV2Settings;
use App\Services\Backup\ConnectorPusher;
use App\Services\Backup\FleetMigrationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The screen the fleet migration happens from.
 *
 * Everything here existed already as a console command — push the connector, install the engine,
 * switch a site over — which meant the migration could only be run by someone with a shell, one
 * site at a time, with no view of where the fleet had got to. That is a fine way to move one pilot
 * site and a poor way to move twenty-nine: the thing you need most is not the commands, it is
 * knowing which sites are done, which are blocked, and why.
 */
class FleetMigration extends Component
{
    public bool $enabled = false;

    /** Set while a fleet-wide run is in flight, so the console can follow it. */
    public ?string $runId = null;

    public string $filter = 'all';

    public function mount(): void
    {
        $this->enabled = BackupV2Access::allows(auth()->user());

        if (! $this->enabled) {
            abort(404);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function rows(): array
    {
        $sites = Site::withoutTrashed()
            ->with('backupConfig')
            ->orderBy('name')
            ->get();

        $migration = app(FleetMigrationService::class);
        $rows = [];

        foreach ($sites as $site) {
            $status = $migration->status($site);

            $rows[] = match ($this->filter) {
                'pending' => $status['ready'] && $status['steps'] !== [] ? $status : null,
                'done' => $status['effective_engine'] === BackupEngine::V2 ? $status : null,
                'blocked' => ! $status['ready'] ? $status : null,
                default => $status,
            };
        }

        return array_values(array_filter($rows));
    }

    /**
     * @return array{total: int, on_v2: int, ready: int, blocked: int}
     */
    #[Computed]
    public function summary(): array
    {
        $migration = app(FleetMigrationService::class);
        $total = 0;
        $onV2 = 0;
        $ready = 0;
        $blocked = 0;

        foreach (Site::withoutTrashed()->with('backupConfig')->get() as $site) {
            $status = $migration->status($site);
            $total++;
            if ($status['effective_engine'] === BackupEngine::V2) {
                $onV2++;
            } elseif (! $status['ready']) {
                $blocked++;
            } else {
                $ready++;
            }
        }

        return ['total' => $total, 'on_v2' => $onV2, 'ready' => $ready, 'blocked' => $blocked];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Computed]
    public function progress(): array
    {
        if ($this->runId === null) {
            return [];
        }

        return (array) Cache::get(MigrateSiteToBackupV2::cacheKey($this->runId), []);
    }

    #[Computed]
    public function restoreEnabled(): bool
    {
        return app(BackupV2Settings::class)->restoreEnabled();
    }

    #[Computed]
    public function fleetEnrolment(): bool
    {
        return app(BackupV2Settings::class)->fleetEnrolmentEnabled();
    }

    /**
     * Allow any site to be enrolled, instead of only the ones the deployment names.
     *
     * Without it, moving a site means editing an environment variable and redeploying — and the
     * deploy token this manager holds cannot even write environment variables, so that switch would
     * live somewhere nobody working in the product could reach. The master kill switch is untouched:
     * `BACKUP_ENGINE_V2_ENABLED=false` still stops every site at once, from the deployment side.
     */
    public function toggleFleetEnrolment(): void
    {
        $this->guard();

        $settings = app(BackupV2Settings::class);
        $settings->setFleetEnrolmentEnabled(! $settings->fleetEnrolmentEnabled());

        unset($this->fleetEnrolment, $this->rows, $this->summary);

        session()->flash('fleet-success', $settings->fleetEnrolmentEnabled()
            ? __('Any site may now be moved to the V2 engine.')
            : __('Only sites named in the deployment may run the V2 engine.'));
    }

    /**
     * Turn V2 restores on or off for everyone.
     *
     * It shipped as an environment variable defaulting to false, which was right while the engine
     * was unproven and wrong the moment it was not: restores are demonstrated on a client site, and
     * still nobody without a shell can start one.
     */
    public function toggleRestore(): void
    {
        $this->guard();

        $settings = app(BackupV2Settings::class);
        $settings->setRestoreEnabled(! $settings->restoreEnabled());

        unset($this->restoreEnabled);
        session()->flash('fleet-success', $settings->restoreEnabled()
            ? __('Restores from the V2 engine are enabled.')
            : __('Restores from the V2 engine are disabled.'));
    }

    public function migrateSite(int $siteId): void
    {
        $this->guard();

        $site = Site::withoutTrashed()->findOrFail($siteId);

        $this->runId ??= (string) Str::uuid();
        MigrateSiteToBackupV2::dispatch($site, $this->runId);

        session()->flash('fleet-success', __('Migrating :site…', ['site' => $site->domain]));
        unset($this->rows, $this->summary);
    }

    /**
     * Every site that is ready and not already there.
     *
     * Deliberately not "every site": one that is blocked stays blocked, with its reason on screen,
     * rather than being attempted twenty-nine times over.
     */
    public function migrateAllReady(): void
    {
        $this->guard();

        $migration = app(FleetMigrationService::class);
        $this->runId = (string) Str::uuid();
        $queued = 0;

        foreach (Site::withoutTrashed()->with('backupConfig')->get() as $site) {
            $status = $migration->status($site);
            if (! $status['ready'] || $status['steps'] === []) {
                continue;
            }

            MigrateSiteToBackupV2::dispatch($site, $this->runId);
            $queued++;
        }

        session()->flash('fleet-success', trans_choice(
            '{0}Nothing to migrate.|{1}Migrating 1 site…|[2,*]Migrating :count sites…',
            $queued,
            ['count' => $queued],
        ));

        unset($this->rows, $this->summary);
    }

    /**
     * Put one site back on the old engine.
     *
     * The whole safety net for a same-day migration: one write, no deploy, effective at the next
     * minute because the dispatcher reads the column every time it runs.
     */
    public function setEngine(int $siteId, string $engine): void
    {
        $this->guard();

        $site = Site::withoutTrashed()->findOrFail($siteId);
        $target = BackupEngine::tryFrom($engine) ?? BackupEngine::V1;

        app(FleetMigrationService::class)->setEngine($site, $target);

        session()->flash('fleet-success', __(':site now runs :engine.', [
            'site' => $site->domain,
            'engine' => strtoupper($target->value),
        ]));

        unset($this->rows, $this->summary);
    }

    /**
     * Re-read what each site is actually running, rather than what we last recorded.
     */
    public function refreshVersions(): void
    {
        $this->guard();

        $installer = app(BackupPluginInstaller::class);

        foreach (Site::withoutTrashed()->where('is_connected', true)->get() as $site) {
            $installer->probe($site);
        }

        session()->flash('fleet-success', __('Versions refreshed.'));
        unset($this->rows, $this->summary);
    }

    public function pushConnector(int $siteId): void
    {
        $this->guard();

        $site = Site::withoutTrashed()->findOrFail($siteId);
        $result = app(ConnectorPusher::class)->push($site);

        session()->flash(
            $result['ok'] ? 'fleet-success' : 'fleet-error',
            $site->domain.': '.$result['message'],
        );

        unset($this->rows, $this->summary);
    }

    public function installPlugin(int $siteId): void
    {
        $this->guard();

        $site = Site::withoutTrashed()->findOrFail($siteId);
        $result = app(BackupPluginInstaller::class)->install($site);

        session()->flash(
            $result['ok'] ? 'fleet-success' : 'fleet-error',
            $site->domain.': '.$result['message'],
        );

        unset($this->rows, $this->summary);
    }

    private function guard(): void
    {
        if (! BackupV2Access::allows(auth()->user())) {
            abort(403);
        }
    }

    public function render()
    {
        return view('livewire.backup.v2.fleet-migration')
            ->layout('components.layouts.app', ['title' => __('Backup V2 — fleet')]);
    }
}
