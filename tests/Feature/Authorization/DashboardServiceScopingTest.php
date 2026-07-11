<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P0-22: the global dashboard + DashboardService must be tenant-scoped. A
 * non-admin (Manager/Viewer) may only see the sites they own or reach through
 * an assigned client; admins are unaffected.
 */
class DashboardServiceScopingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();
    }

    private function service(): DashboardService
    {
        return app(DashboardService::class);
    }

    public function test_viewer_sites_overview_only_returns_own_sites(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $other = User::factory()->create(['role' => UserRole::Manager]);

        $mine = Site::factory()->create(['user_id' => $viewer->id]);
        Site::factory()->create(['user_id' => $other->id]);
        Site::factory()->create(['user_id' => $other->id]);

        $this->actingAs($viewer);

        $overview = $this->service()->getSitesOverview();
        $ids = $overview->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
        $this->assertSame(1, $this->service()->getStats()['total_sites']);
    }

    public function test_viewer_sees_sites_of_assigned_client(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $client = Client::factory()->create();
        $viewer->assignedClients()->attach($client);

        $own = Site::factory()->create(['user_id' => $viewer->id]);
        $clientSite = Site::factory()->forClient($client)->create();
        Site::factory()->create(); // unrelated, other owner

        $this->actingAs($viewer);

        $ids = $this->service()->getSitesOverview()->pluck('id')->sort()->values()->all();

        $this->assertEqualsCanonicalizing([$own->id, $clientSite->id], $ids);
        $this->assertSame(2, $this->service()->getStats()['total_sites']);
    }

    public function test_admin_sees_all_sites(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        Site::factory()->count(3)->create();

        $this->actingAs($admin);

        $this->assertSame(3, $this->service()->getSitesOverview()->total());
        $this->assertSame(3, $this->service()->getStats()['total_sites']);
    }

    public function test_stats_are_cached_per_user_scope(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $manager = User::factory()->create(['role' => UserRole::Manager]);

        Site::factory()->create(['user_id' => $manager->id]);
        Site::factory()->count(2)->create(); // other owners

        // Admin populates its own scope first…
        $this->actingAs($admin);
        $this->assertSame(3, $this->service()->getStats()['total_sites']);

        // …the manager must not read the admin-scoped cached value.
        $this->actingAs($manager);
        $this->assertSame(1, $this->service()->getStats()['total_sites']);
    }
}
