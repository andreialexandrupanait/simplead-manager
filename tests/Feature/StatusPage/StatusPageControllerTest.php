<?php

namespace Tests\Feature\StatusPage;

use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StatusPageControllerTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------ //
    //  Public show page
    // ------------------------------------------------------------------ //

    public function test_public_status_page_renders_successfully(): void
    {
        $statusPage = StatusPage::factory()->create([
            'is_public' => true,
            'slug' => 'test-page',
        ]);

        $this->get(route('status-page.show', 'test-page'))
            ->assertOk()
            ->assertViewIs('status-page.show');
    }

    public function test_non_public_status_page_returns_404(): void
    {
        StatusPage::factory()->create([
            'is_public' => false,
            'slug' => 'private-page',
        ]);

        $this->get(route('status-page.show', 'private-page'))
            ->assertNotFound();
    }

    public function test_non_existent_slug_returns_404(): void
    {
        $this->get(route('status-page.show', 'does-not-exist'))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------ //
    //  Password protection
    // ------------------------------------------------------------------ //

    public function test_password_protected_page_shows_password_form(): void
    {
        StatusPage::factory()->create([
            'is_public' => true,
            'slug' => 'protected-page',
            'password_hash' => Hash::make('secret'),
        ]);

        $this->get(route('status-page.show', 'protected-page'))
            ->assertOk()
            ->assertViewIs('status-page.password');
    }

    public function test_correct_password_grants_access_via_session(): void
    {
        $statusPage = StatusPage::factory()->create([
            'is_public' => true,
            'slug' => 'protected-page',
            'password_hash' => Hash::make('secret'),
        ]);

        // Submit correct password
        $this->post(route('status-page.auth', 'protected-page'), [
            'password' => 'secret',
        ])->assertRedirect(route('status-page.show', 'protected-page'));

        // Follow the redirect — session should now grant access
        $this->withSession(["status-page-auth.{$statusPage->id}" => true])
            ->get(route('status-page.show', 'protected-page'))
            ->assertOk()
            ->assertViewIs('status-page.show');
    }

    public function test_wrong_password_shows_error(): void
    {
        StatusPage::factory()->create([
            'is_public' => true,
            'slug' => 'protected-page',
            'password_hash' => Hash::make('secret'),
        ]);

        $this->post(route('status-page.auth', 'protected-page'), [
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('password');
    }

    // ------------------------------------------------------------------ //
    //  JSON API
    // ------------------------------------------------------------------ //

    public function test_json_api_returns_data_for_public_page(): void
    {
        StatusPage::factory()->create([
            'is_public' => true,
            'slug' => 'api-page',
        ]);

        $this->getJson(route('status-page.api', 'api-page'))
            ->assertOk()
            ->assertJsonStructure([
                'status',
                'data' => [
                    'title',
                    'description',
                    'overall_status',
                    'sites',
                    'active_incidents',
                    'recent_incidents',
                    'scheduled_maintenance',
                ],
            ])
            ->assertJson(['status' => 'ok']);
    }

    public function test_json_api_returns_404_for_non_public_page(): void
    {
        StatusPage::factory()->create([
            'is_public' => false,
            'slug' => 'private-api-page',
        ]);

        $this->getJson(route('status-page.api', 'private-api-page'))
            ->assertNotFound();
    }

    // ------------------------------------------------------------------ //
    //  No auth middleware required
    // ------------------------------------------------------------------ //

    public function test_unauthenticated_user_can_view_public_page(): void
    {
        StatusPage::factory()->create([
            'is_public' => true,
            'slug' => 'open-page',
        ]);

        // Ensure we are NOT acting as any user
        $this->assertGuest();

        $this->get(route('status-page.show', 'open-page'))
            ->assertOk()
            ->assertViewIs('status-page.show');
    }
}
