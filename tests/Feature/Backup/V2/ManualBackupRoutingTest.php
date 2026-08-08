<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Enums\BackupEngine;
use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Livewire\Sites\Detail\SiteBackups;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The buttons and the scheduler have to agree about which engine runs a site.
 *
 * They did not. The dispatcher routed on the column while "Backup now" went
 * straight to the old engine, so a site that had been moved would still get the
 * old one from the UI. The two engines do not share a lock namespace — that is
 * deliberate, so each can recognise its own — which means a disagreement here is
 * exactly how both end up working on one client's site at the same time.
 */
class ManualBackupRoutingTest extends TestCase
{
    use RefreshDatabase;

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
        ]);

        Config::set('backup_v2.enabled', true);
        Config::set('backup_v2.site_ids', [(string) $site->id]);

        return $site->fresh();
    }

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        Http::fake();
        $this->actingAs(User::factory()->create(['role' => 'admin']));
    }

    public function test_backup_now_uses_the_engine_the_site_is_on(): void
    {
        $site = $this->siteOnNewEngine();

        Livewire::test(SiteBackups::class, ['site' => $site])->call('backupFull');

        Queue::assertNotPushed(CreateBackup::class);
        $this->assertDatabaseHas('backup_sessions', ['site_id' => $site->id, 'type' => 'full']);
        // And it is visible in the V1 history the user is looking at.
        $this->assertDatabaseHas('backups', ['site_id' => $site->id, 'engine' => 'v2', 'status' => 'pending']);
    }

    /**
     * A button labelled "Backup Database" makes a database backup.
     *
     * It did not. The new engine was asked for a `full` and the label was left
     * alone, on the reasoning that a restore point should hold files and the
     * database together — sound as a default, but it was applied by overriding
     * what the person had clicked, silently, with nothing on screen to say so.
     *
     * The engine could always scope a run: SessionActions::startBackup accepts
     * `database` and defaultScope() turns off files for it. Nothing ever asked.
     */
    public function test_a_database_only_request_makes_a_database_only_backup(): void
    {
        $site = $this->siteOnNewEngine();

        Livewire::test(SiteBackups::class, ['site' => $site])->call('backupDatabase');

        $this->assertDatabaseHas('backup_sessions', ['site_id' => $site->id, 'type' => 'database']);
        Queue::assertNotPushed(CreateBackup::class);

        $session = \App\Backup\V2\Models\BackupSession::where('site_id', $site->id)->firstOrFail();
        $this->assertTrue($session->scope['database'], 'the dump is in scope');
        $this->assertFalse($session->scope['files'], 'the files are not');
    }

    public function test_a_site_on_the_old_engine_still_uses_it(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => StorageDestination::factory()->create(['type' => 'local'])->id,
        ]);

        Livewire::test(SiteBackups::class, ['site' => $site->fresh()])->call('backupFull');

        Queue::assertPushed(CreateBackup::class);
        $this->assertDatabaseCount('backup_sessions', 0);
    }

    public function test_an_incremental_from_the_ui_is_routed_too(): void
    {
        $site = $this->siteOnNewEngine();

        Livewire::test(SiteBackups::class, ['site' => $site])->call('backupIncremental');

        Queue::assertNotPushed(CreateIncrementalBackup::class);
        $this->assertDatabaseCount('backup_sessions', 1);
    }
}
