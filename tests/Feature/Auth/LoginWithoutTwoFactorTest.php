<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App-level two-factor authentication was removed; login must go straight
 * through. The legacy users.two_factor_* columns were dropped as well.
 */
class LoginWithoutTwoFactorTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_redirects_straight_to_dashboard(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticatedAs($user);
    }

    public function test_two_factor_challenge_route_no_longer_exists(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('two-factor.create'));
        $this->assertFalse(\Illuminate\Support\Facades\Route::has('two-factor.store'));
    }
}
