<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Livewire\Auth\TwoFactorChallenge;
use App\Livewire\Settings\TwoFactorAuthentication;
use App\Models\User;
use App\Services\TwoFactorAuthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/**
 * C-02: TOTP two-factor auth — enrollment, challenge gate, and mandatory
 * enforcement for admins with a grace window.
 */
class TwoFactorAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function otpFor(string $secret): string
    {
        return app(Google2FA::class)->getCurrentOtp($secret);
    }

    public function test_service_verifies_totp_and_consumes_recovery_codes(): void
    {
        $service = app(TwoFactorAuthService::class);
        $secret = $service->generateSecret();

        $user = User::factory()->withTwoFactor($secret, ['CODE1-CODE1', 'CODE2-CODE2'])->create();

        $this->assertTrue($service->verifyCode($user, $this->otpFor($secret)));
        $this->assertFalse($service->verifyCode($user, '000000'));

        $this->assertTrue($service->verifyAndConsumeRecoveryCode($user, 'CODE1-CODE1'));
        // Consumed — cannot be reused.
        $this->assertFalse($service->verifyAndConsumeRecoveryCode($user->fresh(), 'CODE1-CODE1'));
        $this->assertTrue($service->verifyAndConsumeRecoveryCode($user->fresh(), 'CODE2-CODE2'));
    }

    public function test_enrollment_requires_a_valid_code_and_returns_recovery_codes(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $component = Livewire::test(TwoFactorAuthentication::class)
            ->call('startEnrollment')
            ->assertSet('enrolling', true);

        $secret = session('two_factor:pending_secret');
        $this->assertNotNull($secret);

        // Wrong code does not enable.
        $component->set('confirmCode', '000000')->call('confirm')->assertHasErrors('confirmCode');
        $this->assertNull($user->fresh()->two_factor_confirmed_at);

        // Correct code enables and yields recovery codes.
        $component->set('confirmCode', $this->otpFor($secret))
            ->call('confirm')
            ->assertSet('showingRecoveryCodes', true);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasTwoFactorEnabled());
        $this->assertCount(8, $fresh->two_factor_recovery_codes);
    }

    public function test_disabling_requires_the_current_password(): void
    {
        $secret = app(TwoFactorAuthService::class)->generateSecret();
        $user = User::factory()->withTwoFactor($secret)->create();
        $this->actingAs($user);

        Livewire::test(TwoFactorAuthentication::class)
            ->set('password', 'wrong-password')
            ->call('disable')
            ->assertHasErrors('password');
        $this->assertTrue($user->fresh()->hasTwoFactorEnabled());

        Livewire::test(TwoFactorAuthentication::class)
            ->set('password', 'password')
            ->call('disable')
            ->assertHasNoErrors();
        $this->assertFalse($user->fresh()->hasTwoFactorEnabled());
    }

    public function test_enabled_user_is_redirected_to_challenge_until_verified(): void
    {
        $secret = app(TwoFactorAuthService::class)->generateSecret();
        $user = User::factory()->withTwoFactor($secret)->create();

        // Authenticated but not challenged → gated routes bounce to the challenge.
        $this->actingAs($user)->get('/')->assertRedirect(route('two-factor.challenge'));

        // Passing the challenge sets the session flag and grants access.
        Livewire::test(TwoFactorChallenge::class)
            ->set('code', $this->otpFor($secret))
            ->call('submit');

        $this->assertTrue(session('auth.two_factor_confirmed'));
        $this->actingAs($user)->get('/')->assertOk();
    }

    public function test_challenge_rejects_a_bad_code(): void
    {
        $secret = app(TwoFactorAuthService::class)->generateSecret();
        $user = User::factory()->withTwoFactor($secret)->create();
        $this->actingAs($user);

        Livewire::test(TwoFactorChallenge::class)
            ->set('code', '000000')
            ->call('submit')
            ->assertHasErrors('code');

        $this->assertNotTrue(session('auth.two_factor_confirmed'));
    }

    public function test_admin_without_2fa_is_forced_to_enroll_after_the_grace_window(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_grace_started_at' => now()->subDays(30)]);

        $this->actingAs($admin)->get('/')->assertRedirect(route('settings.two-factor'));
    }

    public function test_admin_within_grace_is_allowed_through(): void
    {
        $admin = User::factory()->admin()->create(['two_factor_grace_started_at' => null]);

        // First hit stamps the grace start and lets them through.
        $this->actingAs($admin)->get('/')->assertOk();
        $this->assertNotNull($admin->fresh()->two_factor_grace_started_at);
    }

    public function test_non_admin_without_2fa_is_not_forced(): void
    {
        $user = User::factory()->manager()->create();

        $this->actingAs($user)->get('/')->assertOk();
        $this->assertNull($user->fresh()->two_factor_grace_started_at);
    }
}
