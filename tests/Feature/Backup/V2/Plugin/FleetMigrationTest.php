<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Plugin;

use App\Enums\BackupEngine;
use App\Enums\UserRole;
use App\Jobs\MigrateSiteToBackupV2;
use App\Livewire\Backup\V2\FleetMigration;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use App\Services\Backup\BackupV2Settings;
use App\Services\Backup\FleetMigrationService;
use App\Support\PluginPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Moving the fleet onto the new engine, from a screen.
 *
 * All three steps existed as console commands, which meant the migration could only be run by
 * someone with a shell, one site at a time, with no view of where the fleet had got to. For one
 * pilot site that is fine. For twenty-nine it is not the commands you need, it is knowing which
 * sites are done, which are blocked, and why.
 */
#[Group('backup-v2')]
class FleetMigrationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private StorageDestination $destination;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config(['backup_v2.enabled' => true, 'backup_v2.ui_enabled' => true, 'backup_v2.site_ids' => ['*']]);

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);

        // Whatever else the test database has, object storage is the fleet's default here — the
        // resolver picks the first default it finds, and a leftover local one would answer instead.
        StorageDestination::query()->update(['is_default' => false, 'is_active' => false]);
        $this->destination = StorageDestination::factory()->create([
            'type' => 'hetzner_objectstorage',
            'is_default' => true,
            'is_active' => true,
        ]);
    }

    public function test_a_site_that_needs_all_three_steps_says_so(): void
    {
        $site = $this->site(connector: '2.23.1', plugin: null, engine: BackupEngine::V1);

        $status = app(FleetMigrationService::class)->status($site);

        $this->assertTrue($status['ready']);
        $this->assertSame(['connector', 'plugin', 'engine'], $status['steps']);
    }

    public function test_a_site_already_there_needs_nothing(): void
    {
        $site = $this->site(
            connector: '2.25.0',
            plugin: PluginPackage::backupEngine()->version(),
            engine: BackupEngine::V2,
        );

        $status = app(FleetMigrationService::class)->status($site);

        $this->assertSame([], $status['steps']);
        $this->assertSame(BackupEngine::V2, $status['effective_engine']);
    }

    public function test_an_unreachable_site_is_not_ready_and_the_reason_is_written_down(): void
    {
        // A row that says "not ready" and nothing else is a question nobody can act on.
        $site = $this->site(connector: null, plugin: null, engine: BackupEngine::V1, connected: false);

        $status = app(FleetMigrationService::class)->status($site);

        $this->assertFalse($status['ready']);
        $this->assertStringContainsString('connector', (string) $status['blocked_by']);
        $this->assertSame([], $status['steps'], 'nothing is attempted against a site we cannot reach');
    }

    public function test_storage_the_engine_cannot_write_to_blocks_rather_than_queues_work(): void
    {
        $dropbox = StorageDestination::factory()->create(['type' => 'dropbox', 'is_active' => true, 'is_default' => false]);
        $site = $this->site(connector: '2.25.0', plugin: '0.8.0', engine: BackupEngine::V1);
        $site->backupConfig->forceFill(['storage_destination_id' => $dropbox->id])->save();

        $status = app(FleetMigrationService::class)->status($site->fresh());

        $this->assertFalse($status['ready']);
        $this->assertStringContainsString('dropbox', (string) $status['blocked_by']);
    }

    public function test_the_console_shows_what_would_run_tonight_not_only_the_column(): void
    {
        // The column says V2, the gate says otherwise. What runs is the gate's answer, and showing
        // only the column would be presenting an intention as a fact.
        config(['backup_v2.site_ids' => []]);
        $site = $this->site(connector: '2.25.0', plugin: '0.8.0', engine: BackupEngine::V2);

        $status = app(FleetMigrationService::class)->status($site);

        $this->assertSame(BackupEngine::V2, $status['engine']);
        $this->assertSame(BackupEngine::V1, $status['effective_engine']);
    }

    public function test_migrating_the_fleet_queues_only_the_sites_that_can_move(): void
    {
        $needsWork = $this->site(connector: '2.23.1', plugin: null, engine: BackupEngine::V1);
        $blocked = $this->site(connector: null, plugin: null, engine: BackupEngine::V1, connected: false);
        $done = $this->site(connector: '2.25.0', plugin: PluginPackage::backupEngine()->version(), engine: BackupEngine::V2);

        Livewire::actingAs($this->admin)
            ->test(FleetMigration::class)
            ->call('migrateAllReady');

        Queue::assertPushed(MigrateSiteToBackupV2::class, fn ($job) => $job->site->is($needsWork));
        Queue::assertNotPushed(MigrateSiteToBackupV2::class, fn ($job) => $job->site->is($blocked));
        Queue::assertNotPushed(MigrateSiteToBackupV2::class, fn ($job) => $job->site->is($done));
    }

    public function test_a_site_can_be_put_back_on_the_old_engine_in_one_click(): void
    {
        // The whole safety net for a same-day migration: one write, no deploy, and the dispatcher
        // picks it up at the next minute.
        $site = $this->site(connector: '2.25.0', plugin: '0.8.0', engine: BackupEngine::V2);

        Livewire::actingAs($this->admin)
            ->test(FleetMigration::class)
            ->call('setEngine', $site->id, 'v1');

        $this->assertSame(BackupEngine::V1, $site->backupConfig->fresh()->backup_engine);
    }

    public function test_restores_can_be_turned_on_from_the_screen(): void
    {
        config(['backup_v2.restore_enabled' => false]);

        $this->assertFalse(app(BackupV2Settings::class)->restoreEnabled());

        Livewire::actingAs($this->admin)
            ->test(FleetMigration::class)
            ->call('toggleRestore');

        $this->assertTrue(app(BackupV2Settings::class)->restoreEnabled());
    }

    public function test_a_site_whose_engine_says_nothing_is_not_counted_as_migrated(): void
    {
        // The column says V2 and the version column is empty, which is what "we asked and got
        // nothing" looks like. Thirteen sites were in exactly this state and the console showed
        // them green, because it read a version we had written down rather than an answer.
        $site = $this->site(connector: '2.26.0', plugin: null, engine: BackupEngine::V2);
        $site->forceFill(['backup_plugin_error' => 'REST API access is restricted.'])->save();

        $status = app(FleetMigrationService::class)->status($site->fresh());

        $this->assertTrue($status['engine_silent']);
        $this->assertFalse($status['answering']);
        $this->assertSame('REST API access is restricted.', $status['engine_error']);

        $summary = Livewire::actingAs($this->admin)->test(FleetMigration::class)->get('summary');

        $this->assertSame(0, $summary['on_v2'], 'a mute engine is not a migrated site');
        $this->assertSame(1, $summary['blocked']);
    }

    public function test_a_site_that_answers_is_counted_as_migrated(): void
    {
        $this->site(
            connector: '2.26.0',
            plugin: PluginPackage::backupEngine()->version(),
            engine: BackupEngine::V2,
        );

        $summary = Livewire::actingAs($this->admin)->test(FleetMigration::class)->get('summary');

        $this->assertSame(1, $summary['on_v2']);
        $this->assertSame(0, $summary['blocked']);
    }

    public function test_a_non_admin_cannot_reach_the_console(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);

        $this->withoutExceptionHandling();
        Livewire::actingAs($viewer)->test(FleetMigration::class);
    }

    private function site(?string $connector, ?string $plugin, BackupEngine $engine, bool $connected = true): Site
    {
        $site = Site::factory()->create([
            'user_id' => $this->admin->id,
            'is_connected' => $connected,
            'connector_version' => $connector,
            'backup_plugin_version' => $plugin,
        ]);

        // Explicitly on the fleet's object storage: the config factory would otherwise mint a
        // destination of a random type, and half of those are ones this engine cannot write to.
        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'backup_engine' => $engine,
            'storage_destination_id' => $this->destination->id,
        ]);

        return $site->fresh();
    }
}
