<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Enums\UserRole;
use App\Models\Client;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2-59: the SetCurrentSite middleware must authorize that the resolved site is
 * accessible to the authenticated user before setting it as the current
 * context. Otherwise a user could pin another tenant's site as "current" (a
 * cross-tenant IDOR) and downstream views/actions would operate on it.
 *
 * The `/sites/{site}/tweaks` route is a lightweight redirect closure inside the
 * `{site}` prefix group, so hitting it exercises the middleware without
 * rendering a heavy component.
 */
class SetCurrentSiteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private function url(Site $site): string
    {
        return "/sites/{$site->id}/tweaks";
    }

    public function test_owner_can_set_their_site_as_current(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($owner)->get($this->url($site))->assertRedirect();
    }

    public function test_client_assigned_user_can_set_the_site_as_current(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Manager]);
        $member = User::factory()->create(['role' => UserRole::Manager]);
        $client = Client::factory()->create();
        $member->assignedClients()->attach($client->id);
        $site = Site::factory()->create(['user_id' => $owner->id, 'client_id' => $client->id]);

        $this->actingAs($member)->get($this->url($site))->assertRedirect();
    }

    public function test_admin_can_set_any_site_as_current(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Manager]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $site = Site::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($admin)->get($this->url($site))->assertRedirect();
    }

    public function test_unauthorized_user_cannot_set_a_foreign_site_as_current(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Manager]);
        $intruder = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $owner->id]);

        $this->actingAs($intruder)->get($this->url($site))->assertForbidden();
    }
}
