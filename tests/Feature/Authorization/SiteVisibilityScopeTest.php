<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1-01: the canonical Site::visibleTo($user) scope is the single source of
 * truth for tenant visibility. It must mirror User::canAccessSite(): admins
 * see everything; everyone else sees only the sites they own OR reach through
 * an assigned client. A legitimate client-assigned Manager MUST keep seeing
 * their assigned client's sites (the whole reason the scope includes client
 * assignments rather than user_id alone).
 */
class SiteVisibilityScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::fake();
    }

    public function test_scope_returns_only_owned_sites_for_plain_manager(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $other = User::factory()->create(['role' => UserRole::Manager]);

        $mine = Site::factory()->create(['user_id' => $manager->id]);
        Site::factory()->create(['user_id' => $other->id]);
        Site::factory()->create(['user_id' => $other->id]);

        $ids = Site::query()->visibleTo($manager)->pluck('id')->all();

        $this->assertSame([$mine->id], $ids);
    }

    public function test_scope_includes_assigned_client_sites_for_manager(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $client = Client::factory()->create();
        $manager->assignedClients()->attach($client);

        $own = Site::factory()->create(['user_id' => $manager->id]);
        $clientSite = Site::factory()->forClient($client)->create(['user_id' => null]);
        Site::factory()->create(); // unrelated tenant

        $ids = Site::query()->visibleTo($manager)->pluck('id')->sort()->values()->all();

        $this->assertEqualsCanonicalizing([$own->id, $clientSite->id], $ids);
    }

    public function test_scope_returns_everything_for_admin(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Site::factory()->count(4)->create();

        $this->assertSame(4, Site::query()->visibleTo($admin)->count());
    }

    public function test_scope_denies_all_when_user_is_null(): void
    {
        Site::factory()->count(3)->create();

        $this->assertSame(0, Site::query()->visibleTo(null)->count());
    }

    public function test_scope_composes_inside_where_has_without_leaking_or(): void
    {
        // Guards against the classic "orWhere leaks past the outer filter" bug:
        // the OR branch must stay wrapped so an unrelated is_connected=false
        // foreign site can never slip in.
        $manager = User::factory()->create(['role' => UserRole::Manager]);

        $mineConnected = Site::factory()->create(['user_id' => $manager->id, 'is_connected' => true]);
        Site::factory()->create(['user_id' => $manager->id, 'is_connected' => false]);
        Site::factory()->create(['is_connected' => true]); // foreign, connected

        $ids = Site::query()
            ->where('is_connected', true)
            ->visibleTo($manager)
            ->pluck('id')
            ->all();

        $this->assertSame([$mineConnected->id], $ids);
    }
}
