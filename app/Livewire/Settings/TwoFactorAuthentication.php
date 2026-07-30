<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Services\ActivityLogger;
use App\Services\TwoFactorAuthService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * C-02: TOTP two-factor enrollment/management. The pending secret lives in the
 * session (not a public Livewire property) so it is never exposed to the client
 * before confirmation. Freshly generated recovery codes are shown once.
 */
class TwoFactorAuthentication extends Component
{
    private const PENDING_SECRET_KEY = 'two_factor:pending_secret';

    public string $confirmCode = '';

    /**
     * The "disable 2FA" form's password.
     *
     * The regenerate and disable forms render at the same time and used to bind
     * the same property: typing into one filled the other, and a validation
     * error from either surfaced under whichever block rendered the message.
     * They own separate properties now — this one stays named `password`
     * because TwoFactorAuthTest drives the disable flow through it.
     */
    public string $disablePassword = '';

    /** The "regenerate recovery codes" form's password. */
    public string $regeneratePassword = '';

    /** @var list<string> */
    public array $recoveryCodes = [];

    public bool $showingRecoveryCodes = false;

    #[Computed]
    public function enabled(): bool
    {
        return Auth::user()->hasTwoFactorEnabled();
    }

    #[Computed]
    public function enrolling(): bool
    {
        return session()->has(self::PENDING_SECRET_KEY) && ! $this->enabled();
    }

    #[Computed]
    public function qrCodeSvg(): ?string
    {
        $secret = session(self::PENDING_SECRET_KEY);
        if (! is_string($secret)) {
            return null;
        }

        return app(TwoFactorAuthService::class)->qrCodeSvg(Auth::user(), $secret);
    }

    #[Computed]
    public function pendingSecret(): ?string
    {
        return session(self::PENDING_SECRET_KEY);
    }

    public function startEnrollment(TwoFactorAuthService $service): void
    {
        if ($this->enabled()) {
            return;
        }

        session()->put(self::PENDING_SECRET_KEY, $service->generateSecret());
        $this->reset('confirmCode');
        $this->resetErrorBag();
    }

    public function cancelEnrollment(): void
    {
        session()->forget(self::PENDING_SECRET_KEY);
        $this->reset('confirmCode');
        $this->resetErrorBag();
    }

    public function confirm(TwoFactorAuthService $service): void
    {
        $secret = session(self::PENDING_SECRET_KEY);
        if (! is_string($secret)) {
            $this->addError('confirmCode', __('Start enrollment again.'));

            return;
        }

        $this->validate(['confirmCode' => 'required|string']);

        $user = Auth::user();
        $user->two_factor_secret = $secret; // encrypted cast

        if (! $service->verifyCode($user, $this->confirmCode)) {
            $user->two_factor_secret = null;
            $this->addError('confirmCode', __('The provided code is invalid.'));

            return;
        }

        $codes = $service->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        session()->forget(self::PENDING_SECRET_KEY);
        // The current session is already trusted (they just proved possession).
        session()->put('auth.two_factor_confirmed', true);

        $this->recoveryCodes = $codes;
        $this->showingRecoveryCodes = true;
        $this->reset('confirmCode');

        ActivityLogger::log(
            type: 'auth',
            severity: 'info',
            title: 'Two-factor authentication enabled',
            metadata: ['user_id' => $user->id],
        );

        $this->dispatch('notify', type: 'success', message: __('Two-factor authentication is now enabled.'));
    }

    public function regenerateRecoveryCodes(TwoFactorAuthService $service): void
    {
        $this->validate(
            ['regeneratePassword' => 'required|current_password'],
            [
                'regeneratePassword.required' => __('Your current password is required.'),
                'regeneratePassword.current_password' => __('The provided password is incorrect.'),
            ],
        );

        if (! $this->enabled()) {
            return;
        }

        $codes = $service->generateRecoveryCodes();
        Auth::user()->forceFill(['two_factor_recovery_codes' => $codes])->save();

        $this->recoveryCodes = $codes;
        $this->showingRecoveryCodes = true;
        $this->reset('regeneratePassword');

        ActivityLogger::log(
            type: 'auth',
            severity: 'info',
            title: 'Two-factor recovery codes regenerated',
        );

        $this->dispatch('notify', type: 'success', message: __('New recovery codes generated.'));
    }

    public function disable(): void
    {
        $this->validate(
            ['disablePassword' => 'required|current_password'],
            [
                'disablePassword.required' => __('Your current password is required.'),
                'disablePassword.current_password' => __('The provided password is incorrect.'),
            ],
        );

        Auth::user()->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_grace_started_at' => null,
        ])->save();

        session()->forget('auth.two_factor_confirmed');
        $this->reset('disablePassword', 'regeneratePassword', 'recoveryCodes');
        $this->showingRecoveryCodes = false;
        unset($this->enabled);

        ActivityLogger::log(
            type: 'auth',
            severity: 'warning',
            title: 'Two-factor authentication disabled',
        );

        $this->dispatch('notify', type: 'success', message: __('Two-factor authentication has been disabled.'));
    }

    public function render()
    {
        return view('livewire.settings.two-factor-authentication');
    }
}
