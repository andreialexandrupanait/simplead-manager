<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Jobs\CreateBackup;
use App\Jobs\RunSafeUpdate;
use App\Livewire\Updates\UpdatesOverview;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\User;
use App\Services\PluginManagerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class UpdatesOverviewSafeRouteTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = User::factory()->create(['role' => UserRole::Manager]);
    }

    private function siteWithPlugin(bool $safe, bool $backupBeforeUpdates = false): array
    {
        $site = Site::factory()->create([
            'user_id' => $this->manager->id,
            'is_connected' => true,
            'safe_updates_enabled' => $safe,
        ]);
        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'backup_before_updates' => $backupBeforeUpdates,
        ]);
        $plugin = SitePlugin::factory()->create([
            'site_id' => $site->id,
            'slug' => 'akismet',
            'file' => 'akismet/akismet.php',
            'name' => 'Akismet',
            'has_update' => true,
            'version' => '5.0',
            'update_version' => '5.1',
        ]);

        return [$site, $plugin];
    }

    public function test_global_page_routes_safe_site_through_safe_pipeline(): void
    {
        // P0-08: the global /updates page must NOT bypass safe updates.
        Queue::fake();

        // The inline path must never be taken for a safe site.
        $this->mock(PluginManagerService::class, function ($mock) {
            $mock->shouldNotReceive('performUpdate');
        });

        [$site, $plugin] = $this->siteWithPlugin(safe: true);

        Livewire::actingAs($this->manager)
            ->test(UpdatesOverview::class)
            ->call('updateSingle', 'plugin', $plugin->id);

        Queue::assertPushed(RunSafeUpdate::class);
        $this->assertDatabaseHas('safe_updates', [
            'site_id' => $site->id,
            'target' => 'akismet/akismet.php',
            'status' => 'pending',
        ]);
    }

    public function test_global_page_non_safe_site_takes_pre_update_backup_then_updates_inline(): void
    {
        // P0-08: non-safe sites that opted into pre-update backups get one before
        // the inline update runs.
        Queue::fake();

        $this->mock(PluginManagerService::class, function ($mock) {
            $mock->shouldReceive('performUpdate')
                ->once()
                ->andReturn(['success' => true, 'message' => 'ok', 'version' => '5.1']);
        });

        [$site, $plugin] = $this->siteWithPlugin(safe: false, backupBeforeUpdates: true);

        Livewire::actingAs($this->manager)
            ->test(UpdatesOverview::class)
            ->call('updateSingle', 'plugin', $plugin->id);

        Queue::assertNotPushed(RunSafeUpdate::class);
        Queue::assertPushed(CreateBackup::class, fn ($job) => $job->trigger === 'pre_update' && $job->site->id === $site->id);
    }

    public function test_update_all_for_site_queues_safe_updates_for_safe_site(): void
    {
        Queue::fake();

        $this->mock(PluginManagerService::class, function ($mock) {
            $mock->shouldNotReceive('performUpdate');
        });

        [$site, $plugin] = $this->siteWithPlugin(safe: true);

        Livewire::actingAs($this->manager)
            ->test(UpdatesOverview::class)
            ->call('updateAllForSite', $site->id);

        Queue::assertPushed(RunSafeUpdate::class);
        $this->assertDatabaseHas('safe_updates', ['site_id' => $site->id, 'status' => 'pending']);
    }
}
