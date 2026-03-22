<?php

namespace App\Livewire\Traits;

use Illuminate\Support\Facades\RateLimiter;

trait WithRateLimiting
{
    protected function rateLimit(string $action, int|string $identifier, int $maxAttempts = 5, int $decaySeconds = 3600): bool
    {
        $key = "{$action}:{$identifier}:" . auth()->id();

        if (!RateLimiter::attempt($key, $maxAttempts, fn () => true, $decaySeconds)) {
            $this->dispatch('notify', type: 'error', message: 'Too many requests. Please wait before trying again.');
            return false;
        }

        return true;
    }
}
