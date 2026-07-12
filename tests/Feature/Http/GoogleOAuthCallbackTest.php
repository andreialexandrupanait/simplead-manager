<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Models\GoogleConnection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * P1-48: the OAuth callback state check previously passed when BOTH the session
 * state and the returned state were null (`null === null`), a CSRF bypass. The
 * callback must now fail closed unless a non-empty session state exists and
 * matches the returned state.
 */
class GoogleOAuthCallbackTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    public function test_callback_rejects_when_no_session_state_and_no_state_param(): void
    {
        $response = $this->actingAs($this->admin())
            ->withSession([]) // no google_oauth_state was ever set
            ->get(route('google.callback', ['code' => 'attacker-code']));

        $response->assertForbidden();
    }

    public function test_callback_rejects_empty_string_states(): void
    {
        $response = $this->actingAs($this->admin())
            ->withSession(['google_oauth_state' => ''])
            ->get(route('google.callback', ['code' => 'attacker-code', 'state' => '']));

        $response->assertForbidden();
    }

    public function test_callback_rejects_mismatched_state(): void
    {
        $response = $this->actingAs($this->admin())
            ->withSession(['google_oauth_state' => 'the-real-token'])
            ->get(route('google.callback', ['code' => 'c', 'state' => 'a-different-token']));

        $response->assertForbidden();
    }

    public function test_callback_accepts_matching_non_empty_state(): void
    {
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'access',
                'refresh_token' => 'refresh',
                'expires_in' => 3600,
            ], 200),
            'googleapis.com/oauth2/v2/userinfo' => Http::response([
                'id' => '1234567890',
                'email' => 'ga@example.com',
                'name' => 'GA User',
            ], 200),
        ]);

        $response = $this->actingAs($this->admin())
            ->withSession(['google_oauth_state' => 'shared-secret-state'])
            ->get(route('google.callback', ['code' => 'good-code', 'state' => 'shared-secret-state']));

        $response->assertRedirect();
        $this->assertDatabaseHas('google_connections', ['email' => 'ga@example.com']);
    }

    /**
     * P2-49: on re-auth Google may omit refresh_token. The callback must keep
     * the previously stored one instead of wiping it with an empty value.
     */
    public function test_reauth_without_refresh_token_keeps_existing_one(): void
    {
        $existing = GoogleConnection::factory()->create([
            'google_id' => '1234567890',
            'email' => 'ga@example.com',
            'refresh_token' => encrypt('original-refresh'),
        ]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'new-access',
                // No refresh_token returned by Google on re-consent.
                'expires_in' => 3600,
            ], 200),
            'googleapis.com/oauth2/v2/userinfo' => Http::response([
                'id' => '1234567890',
                'email' => 'ga@example.com',
                'name' => 'GA User',
            ], 200),
        ]);

        $this->actingAs($this->admin())
            ->withSession(['google_oauth_state' => 'shared-secret-state'])
            ->get(route('google.callback', ['code' => 'good-code', 'state' => 'shared-secret-state']))
            ->assertRedirect();

        $this->assertSame('original-refresh', decrypt($existing->fresh()->refresh_token));
    }
}
