<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Sites\Detail\SiteCloudflare;
use App\Models\CloudflareConnection;
use App\Models\SiteCloudflare as SiteCloudflareModel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;
use Tests\Traits\CreatesSite;
use Tests\Traits\MocksWordPressApi;

class SiteCloudflareTest extends TestCase
{
    use RefreshDatabase, CreatesSite, MocksWordPressApi;

    private User $user;
    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->site = $this->createSite();
        $this->fakeWordPressApi();
        $this->fakeCloudflareApi();
    }

    public function test_component_renders(): void
    {
        Livewire::actingAs($this->user)
            ->test(SiteCloudflare::class, ['site' => $this->site])
            ->assertOk();
    }

    public function test_shows_connect_form_when_not_connected(): void
    {
        Livewire::actingAs($this->user)
            ->test(SiteCloudflare::class, ['site' => $this->site])
            ->assertSee('Connect Cloudflare')
            ->assertSee('Connect this site to a Cloudflare zone');
    }

    public function test_shows_zone_info_when_connected(): void
    {
        $connection = CloudflareConnection::factory()->create([
            'user_id' => $this->user->id,
        ]);

        SiteCloudflareModel::factory()->create([
            'site_id' => $this->site->id,
            'cloudflare_connection_id' => $connection->id,
            'zone_name' => 'example.com',
            'plan_type' => 'free',
            'status' => 'active',
        ]);

        $this->site->load('siteCloudflare');

        Livewire::actingAs($this->user)
            ->test(SiteCloudflare::class, ['site' => $this->site])
            ->assertSee('example.com')
            ->assertDontSee('Connect Cloudflare');
    }

    public function test_can_disconnect_zone(): void
    {
        $connection = CloudflareConnection::factory()->create([
            'user_id' => $this->user->id,
        ]);

        $siteCloudflare = SiteCloudflareModel::factory()->create([
            'site_id' => $this->site->id,
            'cloudflare_connection_id' => $connection->id,
            'zone_name' => 'disconnect-me.com',
        ]);

        $this->site->load('siteCloudflare');

        Livewire::actingAs($this->user)
            ->test(SiteCloudflare::class, ['site' => $this->site])
            ->call('disconnectZone');

        $this->assertDatabaseMissing('site_cloudflare', [
            'id' => $siteCloudflare->id,
        ]);
    }

    public function test_can_purge_cache(): void
    {
        $connection = CloudflareConnection::factory()->create([
            'user_id' => $this->user->id,
        ]);

        SiteCloudflareModel::factory()->create([
            'site_id' => $this->site->id,
            'cloudflare_connection_id' => $connection->id,
            'zone_id' => 'zone-abc-123',
            'zone_name' => 'cached-site.com',
        ]);

        $this->site->load('siteCloudflare');

        // Fake the Cloudflare purge endpoint specifically
        $this->fakeCloudflareApi([
            'https://api.cloudflare.com/client/v4/zones/zone-abc-123/purge_cache' => Http::response([
                'success' => true,
                'result' => ['id' => 'zone-abc-123'],
            ]),
        ]);

        Livewire::actingAs($this->user)
            ->test(SiteCloudflare::class, ['site' => $this->site])
            ->call('purgeEverything');

        $this->assertDatabaseHas('cloudflare_cache_purges', [
            'site_cloudflare_id' => $this->site->siteCloudflare->id,
            'type' => 'everything',
            'purged_by' => $this->user->id,
        ]);
    }

    public function test_displays_dns_records_when_connected(): void
    {
        $connection = CloudflareConnection::factory()->create([
            'user_id' => $this->user->id,
        ]);

        SiteCloudflareModel::factory()->create([
            'site_id' => $this->site->id,
            'cloudflare_connection_id' => $connection->id,
            'zone_id' => 'zone-dns-test',
            'zone_name' => 'dns-test.com',
        ]);

        $this->site->load('siteCloudflare');

        // Fake DNS records response
        $this->fakeCloudflareApi([
            'https://api.cloudflare.com/client/v4/zones/zone-dns-test/dns_records*' => Http::response([
                'success' => true,
                'result' => [
                    [
                        'id' => 'rec-1',
                        'type' => 'A',
                        'name' => 'dns-test.com',
                        'content' => '93.184.216.34',
                        'ttl' => 1,
                        'proxied' => true,
                    ],
                ],
            ]),
        ]);

        Livewire::actingAs($this->user)
            ->test(SiteCloudflare::class, ['site' => $this->site])
            ->assertSee('dns-test.com');
    }
}
