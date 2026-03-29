<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\SiteCloudflare;
use App\Models\CloudflareConnection;
use App\Models\Site;
use App\Models\SiteCloudflare as SiteCloudflareModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteCloudflareTest extends TestCase
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
    public function user_can_view_cloudflare_page(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SiteCloudflare::class, ['site' => $this->site])
            ->assertOk();
    }

    #[Test]
    public function page_renders_with_existing_cloudflare_connection(): void
    {
        $cf = CloudflareConnection::create([
            'user_id' => $this->admin->id,
            'api_token' => 'test-token-value-here',
            'is_valid' => true,
        ]);

        SiteCloudflareModel::create([
            'site_id' => $this->site->id,
            'cloudflare_connection_id' => $cf->id,
            'zone_id' => 'zone-abc-123',
            'zone_name' => 'example.com',
            'plan_type' => 'free',
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(SiteCloudflare::class, ['site' => $this->site]);

        $component->assertOk();
        $this->assertEquals($cf->id, $component->get('selectedConnectionId'));
    }

    // ─── disconnectZone() ─────────────────────────────────────────────

    #[Test]
    public function user_can_disconnect_cloudflare_zone(): void
    {
        $cf = CloudflareConnection::create([
            'user_id' => $this->admin->id,
            'api_token' => 'test-token-value-here',
            'is_valid' => true,
        ]);

        SiteCloudflareModel::create([
            'site_id' => $this->site->id,
            'cloudflare_connection_id' => $cf->id,
            'zone_id' => 'zone-abc-123',
            'zone_name' => 'example.com',
            'plan_type' => 'free',
        ]);

        Livewire::actingAs($this->admin)
            ->test(SiteCloudflare::class, ['site' => $this->site])
            ->call('disconnectZone');

        $this->assertDatabaseMissing('site_cloudflare', [
            'site_id' => $this->site->id,
        ]);
    }

    // ─── purgeByUrls() ────────────────────────────────────────────────

    #[Test]
    public function purge_by_urls_with_empty_input_flashes_error(): void
    {
        Livewire::actingAs($this->admin)
            ->test(SiteCloudflare::class, ['site' => $this->site])
            ->set('purgeUrls', '   ')
            ->call('purgeByUrls')
            ->assertSee('Please enter at least one URL.');
    }

    // ─── tab switching ────────────────────────────────────────────────

    #[Test]
    public function tab_defaults_to_overview(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SiteCloudflare::class, ['site' => $this->site]);

        $this->assertEquals('overview', $component->get('tab'));
    }

    #[Test]
    public function user_can_switch_tabs(): void
    {
        $component = Livewire::actingAs($this->admin)
            ->test(SiteCloudflare::class, ['site' => $this->site])
            ->set('tab', 'cache');

        $this->assertEquals('cache', $component->get('tab'));
    }

    // ─── Authorization ────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_access_another_users_site_cloudflare(): void
    {
        $viewer = User::factory()->viewer()->create();
        $otherAdmin = User::factory()->admin()->create();
        $otherSite = Site::factory()->for($otherAdmin)->create();

        Livewire::actingAs($viewer)
            ->test(SiteCloudflare::class, ['site' => $otherSite])
            ->assertForbidden();
    }
}
