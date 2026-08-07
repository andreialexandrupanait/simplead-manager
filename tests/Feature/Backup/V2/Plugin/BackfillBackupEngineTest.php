<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Plugin;

use App\Enums\BackupEngine;
use App\Enums\UserRole;
use App\Jobs\MigrateSiteToBackupV2;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use App\Services\Backup\FleetMigrationService;
use App\Support\PluginPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The nightly sweep that puts the engine on sites nobody installed it on.
 *
 * The fleet migration was a one-off run from a screen, and a screen nothing linked to. A site
 * connected afterwards simply stayed on the old engine, silently, until somebody noticed its first
 * backup had run on V1 weeks later. A migration that has to be remembered is one that stops.
 */
#[Group('backup-v2')]
class BackfillBackupEngineTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private StorageDestination $destination;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['backup_v2.enabled' => true, 'backup_v2.site_ids' => ['*']]);

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);

        StorageDestination::query()->update(['is_default' => false, 'is_active' => false]);
        $this->destination = StorageDestination::factory()->create([
            'type' => 'hetzner_objectstorage',
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function test_a_connected_site_missing_the_engine_is_queued(): void
    {
        $site = $this->site(connector: '2.27.0', plugin: null, engine: BackupEngine::V1);

        $this->artisan('backup:backfill-engine')->assertSuccessful();

        Queue::assertPushed(MigrateSiteToBackupV2::class, fn ($job) => $job->site->is($site));
    }

    public function test_a_site_already_on_the_engine_is_left_alone(): void
    {
        // Idempotence is the whole point: this runs every night against the same fleet.
        $this->site(
            connector: '2.27.0',
            plugin: PluginPackage::backupEngine()->version(),
            engine: BackupEngine::V2,
        );

        $this->artisan('backup:backfill-engine')
            ->expectsOutputToContain('1 already on the engine')
            ->assertSuccessful();

        Queue::assertNotPushed(MigrateSiteToBackupV2::class);
    }

    public function test_a_blocked_site_is_reported_rather_than_attempted(): void
    {
        // A site with nowhere to write to cannot be fixed by installing a plugin on it. Skipping it
        // silently would make it indistinguishable from a site that is fine.
        $site = $this->site(connector: '2.27.0', plugin: null, engine: BackupEngine::V1);
        $site->backupConfig->forceFill(['storage_destination_id' => null])->save();
        StorageDestination::query()->update(['is_default' => false, 'is_active' => false]);

        $this->artisan('backup:backfill-engine')
            ->expectsOutputToContain('skipped')
            ->assertSuccessful();

        Queue::assertNotPushed(MigrateSiteToBackupV2::class);
    }

    public function test_a_disconnected_site_is_never_touched(): void
    {
        $this->site(connector: null, plugin: null, engine: BackupEngine::V1, connected: false);

        $this->artisan('backup:backfill-engine')->assertSuccessful();

        Queue::assertNotPushed(MigrateSiteToBackupV2::class);
    }

    public function test_a_site_needing_a_connector_push_is_skipped_not_pushed_to(): void
    {
        // migrate() would push the connector as its first step. Updating the connector across the
        // fleet overnight is a much larger decision than installing a backup engine, and it stays a
        // thing a person does knowing they did it.
        $site = $this->site(connector: '2.23.1', plugin: null, engine: BackupEngine::V1);

        $this->artisan('backup:backfill-engine')
            ->expectsOutputToContain('connector')
            ->assertSuccessful();

        Queue::assertNotPushed(MigrateSiteToBackupV2::class, fn ($job) => $job->site->is($site));
    }

    public function test_dry_run_dispatches_nothing(): void
    {
        $this->site(connector: '2.27.0', plugin: null, engine: BackupEngine::V1);

        $this->artisan('backup:backfill-engine', ['--dry-run' => true])
            ->expectsOutputToContain('would migrate')
            ->assertSuccessful();

        Queue::assertNotPushed(MigrateSiteToBackupV2::class);
    }

    public function test_a_site_with_no_nightly_schedule_is_called_out(): void
    {
        // The engine alone does not back a site up. Installing it and saying nothing would report
        // success over a site that still has no restore point coming.
        $site = $this->site(
            connector: '2.27.0',
            plugin: PluginPackage::backupEngine()->version(),
            engine: BackupEngine::V2,
        );
        $site->backupConfig->forceFill(['is_enabled' => false])->save();

        $this->artisan('backup:backfill-engine')
            ->expectsOutputToContain('no nightly schedule')
            ->assertSuccessful();
    }

    public function test_a_site_with_no_config_at_all_gets_one_on_the_fleets_defaults(): void
    {
        // backup_configs is only ever created automatically for a site whose maintenance plan
        // carries an enabled backup module. A site without a plan has no row, so setEngine has to
        // make one — and making it on the schema defaults would leave UTC, a retention of 10 and no
        // destination: configured-looking and wrong in three places nobody would think to check.
        $site = Site::factory()->create([
            'user_id' => $this->admin->id,
            'is_connected' => true,
            'connector_version' => '2.27.0',
            'backup_plugin_version' => PluginPackage::backupEngine()->version(),
        ]);
        $this->assertNull($site->backupConfig);

        app(FleetMigrationService::class)->setEngine($site, BackupEngine::V2);

        $config = $site->fresh()->backupConfig;
        $this->assertNotNull($config);
        $this->assertSame(BackupEngine::V2, $config->backup_engine);
        $this->assertSame(config('app.timezone'), $config->timezone);
        $this->assertSame(30, $config->retention_value);
        $this->assertSame('03:00', $config->time);
        $this->assertSame($this->destination->id, $config->storage_destination_id);

        // Starting a nightly schedule spends a client's storage, so it stays an explicit decision
        // made on the site's own screen.
        $this->assertFalse($config->is_enabled);
    }

    public function test_an_existing_config_is_not_rewritten_by_a_migration(): void
    {
        $site = $this->site(connector: '2.27.0', plugin: null, engine: BackupEngine::V1);
        $site->backupConfig->forceFill([
            'time' => '23:45',
            'retention_value' => 7,
            'is_enabled' => true,
        ])->save();

        app(FleetMigrationService::class)->setEngine($site->fresh(), BackupEngine::V2);

        $config = $site->fresh()->backupConfig;
        $this->assertSame(BackupEngine::V2, $config->backup_engine);
        $this->assertSame('23:45', $config->time, 'the schedule someone chose is not overwritten');
        $this->assertSame(7, $config->retention_value);
        $this->assertTrue($config->is_enabled);
    }

    private function site(?string $connector, ?string $plugin, BackupEngine $engine, bool $connected = true): Site
    {
        $site = Site::factory()->create([
            'user_id' => $this->admin->id,
            'is_connected' => $connected,
            'connector_version' => $connector,
            'backup_plugin_version' => $plugin,
        ]);

        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'backup_engine' => $engine,
            'storage_destination_id' => $this->destination->id,
            'is_enabled' => true,
        ]);

        return $site->fresh();
    }
}
