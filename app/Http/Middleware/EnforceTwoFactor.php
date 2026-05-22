<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceTwoFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $user->two_factor_enabled) {
            return $next($request);
        }

        $settings = app(SettingsService::class);
        $enforced = $settings->get('mfa_required', false);

        if (! $enforced) {
            return $next($request);
        }

        // Allow access to profile settings (where 2FA is configured), logout,
        // and Livewire update endpoint (otherwise the in-page 2FA setup AJAX
        // call is redirected and never completes — user sees only a flicker).
        if ($request->routeIs('settings.profile', 'logout', 'password.*') || $request->is('livewire*')) {
            return $next($request);
        }

        return redirect()->route('settings.profile')
            ->with('warning', 'Your administrator requires two-factor authentication. Please enable it to continue.');
    }
}
