<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * C-02: two-factor auth is MANDATORY for admins. To avoid locking existing
 * admins out on deploy, enforcement is graced: the window starts at an admin's
 * first authenticated request without 2FA (stamped here), during which they are
 * nagged; once it elapses they are redirected to enrollment and cannot use the
 * app until enrolled. Non-admins and already-enrolled admins pass through.
 */
class EnforceTwoFactorEnrollment
{
    /**
     * Routes an un-enrolled admin may still reach — enrollment itself and the
     * ways out — so enforcement can never lock someone out of fixing it.
     */
    private const EXEMPT_ROUTES = [
        'settings.two-factor',
        'two-factor.*',
        'logout',
        'verification.*',
        'password.confirm',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->isAdmin() || $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        if ($request->routeIs(self::EXEMPT_ROUTES)) {
            return $next($request);
        }

        // Start the grace window at first exposure so existing admins aren't
        // locked out the instant this deploys.
        if ($user->two_factor_grace_started_at === null) {
            $user->forceFill(['two_factor_grace_started_at' => now()])->save();
        }

        $deadline = $user->two_factor_grace_started_at
            ->copy()
            ->addDays((int) config('twofactor.admin_grace_days', 7));

        if (now()->greaterThanOrEqualTo($deadline)) {
            return redirect()->route('settings.two-factor')
                ->with('error', __('Two-factor authentication is required for admins. Enroll now to continue.'));
        }

        // Within grace: let them through but nag once per request.
        session()->flash('warning', __('Two-factor authentication will be required soon. Please set it up in your security settings.'));

        return $next($request);
    }
}
