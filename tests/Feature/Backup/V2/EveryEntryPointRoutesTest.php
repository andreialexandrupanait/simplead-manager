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
     * A site deliberately held on the old engine still gets it. The per-site
     * rollback is a documented lever and it stays real until the engine concept
     * is removed — what this suite forbids is reaching the old engine by
     * accident, not on purpose.
     */
    public function test_a_site_left_on_the_old_engine_still_gets_it(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        StorageDestination::factory()->create(['type' => 'local', 'is_active' => true, 'is_default' => true]);
        BackupConfig::factory()->create(['site_id' => $site->id, 'is_enabled' => true]);

        app(BackupLauncher::class)->launch($site->fresh(), 'full', 'manual');

        Queue::assertPushed(CreateBackup::class);
        $this->assertDatabaseCount('backup_sessions', 0);
    }

    /**
     * The old engine replays an incremental against a manifest it wrote. Asking
     * for one with no manifest anywhere used to be caught by a guard on the
     * Backups page and nowhere else, so the same request from the fleet page
     * queued a job that could not do the work.
     */
    public function test_an_incremental_with_no_base_is_refused_on_the_old_engine(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        StorageDestination::factory()->create(['type' => 'local', 'is_active' => true, 'is_default' => true]);
        BackupConfig::factory()->create(['site_id' => $site->id, 'is_enabled' => true]);

        $this->expectExceptionMessage('No full backup with manifest found.');

        app(BackupLauncher::class)->launch($site->fresh(), 'incremental', 'manual');
    }
}
