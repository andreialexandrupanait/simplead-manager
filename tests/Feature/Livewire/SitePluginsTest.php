<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\CreateBackup;
use App\Livewire\Sites\Detail\SitePlugins;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SitePluginsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->site = Site::factory()->for($this->admin)->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function user_can_view_plugins_tab(): void
    {
        SitePlugin::factory()->for($this->site)->create([
            'name' => 'WooCommerce',
            'slug' => 'woocommerce',
            'file' => 'woocommerce/woocommerce.php',
            'auto_update' => false,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SitePlugins::class, ['site' => $this->site])
            ->assertOk()
            ->assertSet('tab', 'plugins');
    }

    #[Test]
    public function user_can_view_themes_tab(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SitePlugins::class, ['site' => $this->site])
            ->call('setTab', 'themes')
            ->assertOk()
            ->assertSet('tab', 'themes');
    }

    // ─── Search / Filtering ───────────────────────────────────────────

    #[Test]
    public function user_can_search_plugins(): void
    {
        SitePlugin::factory()->for($this->site)->create([
            'name' => 'WooCommerce',
            'slug' => 'woocommerce',
            'file' => 'woocommerce/woocommerce.php',
            'auto_update' => false,
        ]);

        SitePlugin::factory()->for($this->site)->create([
            'name' => 'Yoast SEO',
            'slug' => 'yoast-seo',
            'file' => 'wordpress-seo/wp-seo.php',
            'auto_update' => false,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(SitePlugins::class, ['site' => $this->site])
            ->set('search', 'WooCommerce');

        // Only the matching plugin should be in the computed collection
        $plugins = $component->instance()->plugins;
        $this->assertCount(1, $plugins);
        $this->assertEquals('WooCommerce', $plugins->first()->name);
    }

    // ─── toggleAutoUpdate() ───────────────────────────────────────────

    #[Test]
    public function user_can_toggle_auto_update_on_plugin(): void
    {
        $plugin = SitePlugin::factory()->for($this->site)->create([
            'auto_update' => false,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SitePlugins::class, ['site' => $this->site])
            ->call('toggleAutoUpdate', 'plugin', $plugin->id)
            ->assertDispatched('notify');

        $this->assertDatabaseHas('site_plugins', [
            'id' => $plugin->id,
            'auto_update' => true,
        ]);

        // Toggle back to off
        Livewire::actingAs($this->admin)
            ->test(SitePlugins::class, ['site' => $this->site])
            ->call('toggleAutoUpdate', 'plugin', $plugin->id);

        $this->assertDatabaseHas('site_plugins', [
            'id' => $plugin->id,
            'auto_update' => false,
        ]);
    }

    // ─── quickBackup() ────────────────────────────────────────────────

    #[Test]
    public function user_can_trigger_quick_backup(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SitePlugins::class, ['site' => $this->site])
            ->call('quickBackup');

        Queue::assertPushed(CreateBackup::class, function (CreateBackup $job) {
            return $job->site->id === $this->site->id
                && $job->type === 'full'
                && $job->trigger === 'manual';
        });
    }

    #[Test]
    public function quick_backup_is_rate_limited(): void
    {
        Queue::fake();

        $rateLimitKey = "backup:{$this->site->id}:{$this->admin->id}";
        RateLimiter::clear($rateLimitKey);

        $component = Livewire::actingAs($this->admin)
            ->test(SitePlugins::class, ['site' => $this->site]);

        // 5 allowed attempts
        for ($i = 0; $i < 5; $i++) {
            $component->call('quickBackup');
        }

        // 6th call must be rate-limited
        $component->call('quickBackup');

        Queue::assertPushed(CreateBackup::class, 5);
    }
}
