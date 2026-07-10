<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\UserRole;
use App\Jobs\RunSafeUpdate;
use App\Livewire\Sites\Detail\SitePlugins;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class SafeUpdateFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_toggle_flips_the_safe_updates_flag(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id, 'safe_updates_enabled' => false]);

        Livewire::actingAs($manager)
            ->test(SitePlugins::class, ['site' => $site])
            ->call('toggleSafeUpdates');

        $this->assertTrue($site->fresh()->safe_updates_enabled);
    }

    public function test_update_routes_through_the_safe_pipeline_when_enabled(): void
    {
        Queue::fake();

        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id, 'safe_updates_enabled' => true]);
        $plugin = SitePlugin::factory()->create([
            'site_id' => $site->id,
            'slug' => 'akismet',
            'file' => 'akismet/akismet.php',
            'has_update' => true,
            'version' => '5.0',
            'update_version' => '5.1',
        ]);

        Livewire::actingAs($manager)
            ->test(SitePlugins::class, ['site' => $site])
            ->call('updatePlugin', $plugin->id);

        Queue::assertPushed(RunSafeUpdate::class);
        // The connector identifier is the plugin FILE, not the slug (AUDIT PM-P0-1).
        $this->assertDatabaseHas('safe_updates', [
            'site_id' => $site->id,
            'target' => 'akismet/akismet.php',
            'status' => 'pending',
        ]);
    }
}
