<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Enums\BackupEngine;
use App\Jobs\CreateBackup;
use App\Livewire\Backups\BackupsOverview;
use App\Livewire\Dashboard\GlobalDashboard;
use App\Livewire\Sites\SitesList;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use App\Operations\OperationContext;
use App\Operations\Operations\CreateBackupOperation;
use App\Services\Backup\BackupLauncher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * EVERY way of starting a backup has to reach the engine the site is on.
 *
 * ManualBackupRoutingTest covers the three buttons on a site's own Backups page,
 * and those three were the only ones that ever checked. Nine other entry points
 * dispatched App\Jobs\CreateBackup directly: the fleet page's "Backup All Sites"
 * and its per-row "Back up now", the sites list, the dashboard, the notification
 * dropdown's retry, onboarding, the operations registry, and — the one that says
 * most about how easily this is missed — RunBackupNowCommand, a console command
 * living under app/Backup/V2 that started the old engine.
 *
 * The consequence is not cosmetic. The engines take the site's operation lock
 * under different refs on purpose, so nothing prevents both running at once: one
 * building an archive on the host while the other chunks the same filesystem and
 * dumps the same database. And it is invisible from the screen — the button says
 * Backup, a backup appears, and only the `engine` column knows which made it.
 *
 * These assertions are deliberately about the OUTCOME (a session exists, the old
 * job was not queued) rather than about BackupLauncher being called, so they keep
 * their meaning if the routing moves again.
 */
class EveryEntryPointRoutesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    private function siteOnNewEngine(): Site
    {
        $site = Site::factory()->create(['is_connected' => true]);
        $destination = StorageDestination::factory()->create([
            'type' => 's3', 'is_active' => true, 'is_default' => true,
        ]);
        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
            'backup_engine' => BackupEngine::V2,
            'is_enabled' => true,
        ]);

        Config::set('backup_v2.enabled', true);
        Config::set('backup_v2.site_ids', ['*']);

        return $site->fresh();
    }

    public function test_the_fleet_page_back_up_now_uses_the_new_engine(): void
    {
        $site = $this->siteOnNewEngine();

        Livewire::test(BackupsOverview::class)->call('backupStaleSite', $site->id);

        Queue::assertNotPushed(CreateBackup::class);
        $this->assertDatabaseHas('backup_sessions', ['site_id' => $site->id]);
    }

    public function test_backup_all_sites_uses_the_new_engine(): void
    {
        $site = $this->siteOnNewEngine();

        Livewire::test(BackupsOverview::class)->call('backupAllSites');

        Queue::assertNotPushed(CreateBackup::class);
        $this->assertDatabaseHas('backup_sessions', ['site_id' => $site->id]);
    }

    public function test_the_sites_list_uses_the_new_engine(): void
    {
        $site = $this->siteOnNewEngine();

        Livewire::test(SitesList::class)->call('runBackup', $site->id);

        Queue::assertNotPushed(CreateBackup::class);
        $this->assertDatabaseHas('backup_sessions', ['site_id' => $site->id]);
    }

    public function test_the_dashboard_uses_the_new_engine(): void
    {
        $site = $this->siteOnNewEngine();

        Livewire::test(GlobalDashboard::class)->call('runBackup', $site->id);

        Queue::assertNotPushed(CreateBackup::class);
        $this->assertDatabaseHas('backup_sessions', ['site_id' => $site->id]);
    }

    public function test_the_operations_registry_uses_the_new_engine(): void
    {
        $site = $this->siteOnNewEngine();

        (new CreateBackupOperation)->execute(new OperationContext(
            site: $site,
            userId: (int) auth()->id(),
            params: ['type' => 'full'],
            runId: 'test-run',
            trackerKey: 'test-tracker',
        ));

        Queue::assertNotPushed(CreateBackup::class);
        $this->assertDatabaseHas('backup_sessions', ['site_id' => $site->id]);
    }

    /**
     * The buttons that moved off the deleted console still work.
     *
     * Pressed, not rendered. Twice today a screen passed every test and failed on
     * the first click, because the tests rendered it and never touched it.
     */
    public function test_a_stopped_backup_can_be_picked_up_from_the_site_page(): void
    {
        $site = $this->siteOnNewEngine();
        $backup = \App\Models\Backup::factory()->create([
            'site_id' => $site->id,
            'status' => \App\Enums\BackupStatus::InProgress,
        ]);
        $session = \App\Backup\V2\Models\BackupSession::create([
            'site_id' => $site->id,
            'backup_id' => $backup->id,
            'type' => 'full',
            'scope' => ['database' => true, 'files' => true],
            'resource_profile' => 'low_impact',
            'state' => \App\Backup\V2\Enums\BackupSessionState::RetryWait,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'idempotency_key' => 'resume-'.uniqid('', true),
            'format_version' => 'simplead-backup/2',
        ]);

        Livewire::test(\App\Livewire\Sites\Detail\SiteBackups::class, ['site' => $site])
            ->call('retryBackup', $backup->id);

        Queue::assertPushed(\App\Backup\V2\Jobs\RunBackupSessionJob::class);
        $this->assertNotNull($session->fresh());
    }

    /** And installing the engine on a site that has not got it. */
    public function test_the_engine_can_be_installed_from_the_site_page(): void
    {
        $site = $this->siteOnNewEngine();

        Livewire::test(\App\Livewire\Sites\Detail\SiteBackups::class, ['site' => $site])
            ->call('installEngine');

        Queue::assertPushed(\App\Jobs\MigrateSiteToBackupV2::class);
    }

    /**
     * The old engine replayed an incremental against a manifest it wrote, and the
     * guard for "there is no base yet" lived on one screen. The planner now
     * answers that question for every caller: asked for an incremental with
     * nothing to build on, it returns a full rather than a failure.
     */
    public function test_an_incremental_with_no_base_becomes_a_full(): void
    {
        $site = $this->siteOnNewEngine();

        app(BackupLauncher::class)->launch($site, 'incremental', 'manual');

        $this->assertDatabaseHas('backup_sessions', ['site_id' => $site->id, 'type' => 'full']);
    }
}
