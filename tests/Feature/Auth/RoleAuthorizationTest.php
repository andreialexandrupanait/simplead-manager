<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RoleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function admin_can_access_settings(): void
    {
        $admin = User::factory()->admin()->create();

        $response = $this->actingAs($admin)->get(route('settings.general'));

        $response->assertStatus(200);
    }

    #[Test]
    public function manager_cannot_access_admin_settings(): void
    {
        $manager = User::factory()->manager()->create();

        $response = $this->actingAs($manager)->get(route('settings.general'));

        $response->assertStatus(403);
    }

    #[Test]
    public function viewer_cannot_access_admin_settings(): void
    {
        $viewer = User::factory()->viewer()->create();

        $response = $this->actingAs($viewer)->get(route('settings.general'));

        $response->assertStatus(403);
    }

    #[Test]
    public function any_role_can_access_profile_settings(): void
    {
        $viewer = User::factory()->viewer()->create();

        $response = $this->actingAs($viewer)->get(route('settings.profile'));

        $response->assertStatus(200);
    }

    #[Test]
    public function any_authenticated_user_can_access_dashboard(): void
    {
        $viewer = User::factory()->viewer()->create();

        $response = $this->actingAs($viewer)->get(route('dashboard'));

        $response->assertStatus(200);
    }
}
