<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function login_page_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response->assertStatus(200);
    }

    #[Test]
    public function users_can_authenticate(): void
    {
        $user = User::factory()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $this->assertAuthenticated();
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    #[Test]
    public function users_cannot_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
    }

    #[Test]
    public function users_can_logout(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    #[Test]
    public function unverified_users_are_redirected_to_verification(): void
    {
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->get('/');

        $response->assertRedirect(route('verification.notice'));
    }

    #[Test]
    public function guests_are_redirected_to_login(): void
    {
        $response = $this->get('/');

        $response->assertRedirect('/login');
    }

    #[Test]
    public function two_factor_redirect_when_enabled(): void
    {
        $user = User::factory()->withTwoFactor()->create();

        $response = $this->post('/login', [
            'email' => $user->email,
            'password' => 'password',
        ]);

        $response->assertRedirect(route('two-factor.create'));
        $this->assertGuest();
    }
}
