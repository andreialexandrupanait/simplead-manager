<?php

declare(strict_types=1);

namespace App\Livewire\Auth;

use App\Services\ActivityLogger;
use App\Services\TwoFactorAuthService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

class TwoFactorChallenge extends Component
{
    public string $code = '';

    public string $recoveryCode = '';

    public bool $useRecovery = false;

    public function mount(): void
    {
        // Only reachable mid-login: authenticated, 2FA enabled, not yet challenged.
        $user = Auth::user();
        if (! $user || ! $user->hasTwoFactorEnabled()) {
            $this->redirectRoute('dashboard', navigate: false);

            return;
        }

        if (session()->get('auth.two_factor_confirmed') === true) {
            $this->redirectIntended(route('dashboard'), navigate: false);
        }
    }

    public function toggleRecovery(): void
    {
        $this->useRecovery = ! $this->useRecovery;
        $this->resetErrorBag();
        $this->code = '';
        $this->recoveryCode = '';
    }

    public function submit(TwoFactorAuthService $service): void
    {
        $this->ensureIsNotRateLimited();

        $user = Auth::user();

        $passed = $this->useRecovery
            ? $service->verifyAndConsumeRecoveryCode($user, $this->recoveryCode)
            : $service->verifyCode($user, $this->code);

        if (! $passed) {
            RateLimiter::hit($this->throttleKey());

            throw ValidationException::withMessages([
                $this->useRecovery ? 'recoveryCode' : 'code' => __('The provided code is invalid.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());

        session()->put('auth.two_factor_confirmed', true);
        session()->regenerate();

        ActivityLogger::log(
            type: 'auth',
            severity: 'info',
            title: 'Two-factor challenge passed',
            description: $this->useRecovery ? 'Verified with a recovery code' : 'Verified with an authenticator code',
            metadata: ['method' => $this->useRecovery ? 'recovery' : 'totp'],
        );

        $this->redirectIntended(route('dashboard'), navigate: false);
    }

    public function logout(): void
    {
        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirectRoute('login', navigate: false);
    }

    private function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), (int) config('twofactor.challenge_max_attempts', 5))) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'code' => __('Too many attempts. Please try again in :seconds seconds.', ['seconds' => $seconds]),
        ]);
    }

    private function throttleKey(): string
    {
        return 'two-factor:'.(Auth::id() ?? 'guest').'|'.request()->ip();
    }

    public function render()
    {
        return view('livewire.auth.two-factor-challenge')
            ->layout('components.layouts.guest', ['title' => __('Two-Factor Authentication')]);
    }
}
