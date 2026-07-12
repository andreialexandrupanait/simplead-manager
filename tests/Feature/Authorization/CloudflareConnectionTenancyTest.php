<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SiteCloudflare;
use App\Models\CloudflareConnection;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P2-52: any non-viewer with access to a site could bind it to — or enumerate
 * the zones of — another user's Cloudflare connection (cross-tenant access).
 * Connection use is now scoped to the owner (or an admin).
 */
class CloudflareConnectionTenancyTest extends TestCase
{
    use RefreshDatabase;

    private function validZoneId(): string
    {
        return str_repeat('a', 32);
    }

    public function test_manager_cannot_use_another_users_connection(): void
    {
        $intruder = User::factory()->create(['role' => UserRole::Manager]);
        $victim = User::factory()->create(['role' => UserRole::Manager]);

        // Intruder has legitimate access to the site, but not to the connection.
        $site = Site::factory()->create(['user_id' => $intruder->id]);
        $victimConnection = CloudflareConnection::factory()->create([
            'user_id' => $victim->id,
            'is_valid' => true,
        ]);

        Http::fake();

        Livewire::actingAs($intruder)
            ->test(SiteCloudflare::class, ['site' => $site])
            ->set('selectedConnectionId', $victimConnection->id)
            ->set('selectedZoneId', $this->validZoneId())
            ->call('connectToZone')
            ->assertForbidden();

        // No zone enumeration / binding call ever left the process.
        Http::assertNothingSent();
        $this->assertDatabaseMissing('site_cloudflare', ['site_id' => $site->id]);
    }

    public function test_connections_list_is_scoped_to_the_current_user(): void
    {
        $intruder = User::factory()->create(['role' => UserRole::Manager]);
        $victim = User::factory()->create(['role' => UserRole::Manager]);

        $site = Site::factory()->create(['user_id' => $intruder->id]);
        $ownConnection = CloudflareConnection::factory()->create(['user_id' => $intruder->id, 'is_valid' => true]);
        $victimConnection = CloudflareConnection::factory()->create(['user_id' => $victim->id, 'is_valid' => true]);

        $connections = Livewire::actingAs($intruder)
            ->test(SiteCloudflare::class, ['site' => $site])
            ->instance()
            ->connections();

        $this->assertTrue($connections->contains('id', $ownConnection->id));
        $this->assertFalse($connections->contains('id', $victimConnection->id));
    }

    public function test_admin_may_enumerate_zones_of_any_connection(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $other = User::factory()->create(['role' => UserRole::Manager]);

        // Site owned by someone else and a connection owned by someone else —
        // an admin is still authorized to use it.
        $site = Site::factory()->create(['user_id' => $other->id]);
        $connection = CloudflareConnection::factory()->create(['user_id' => $other->id, 'is_valid' => true]);

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [['id' => $this->validZoneId(), 'name' => 'example.com', 'status' => 'active']],
                'result_info' => ['total_pages' => 1],
            ], 200),
        ]);

        $zones = Livewire::actingAs($admin)
            ->test(SiteCloudflare::class, ['site' => $site])
            ->set('selectedConnectionId', $connection->id)
            ->instance()
            ->availableZones();

        $this->assertNotEmpty($zones);
        $this->assertSame('example.com', $zones[0]['name']);
    }

    public function test_owner_manager_may_use_their_own_connection(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $owner->id]);
        $connection = CloudflareConnection::factory()->create(['user_id' => $owner->id, 'is_valid' => true]);

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [['id' => $this->validZoneId(), 'name' => 'owned.example', 'status' => 'active']],
                'result_info' => ['total_pages' => 1],
            ], 200),
        ]);

        $zones = Livewire::actingAs($owner)
            ->test(SiteCloudflare::class, ['site' => $site])
            ->set('selectedConnectionId', $connection->id)
            ->instance()
            ->availableZones();

        $this->assertNotEmpty($zones);
        $this->assertSame('owned.example', $zones[0]['name']);
    }
}
