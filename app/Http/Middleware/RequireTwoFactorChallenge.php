<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * C-02: once a user has 2FA enabled, every fresh session must clear a TOTP
 * challenge before reaching the app. The session flag is set by the challenge
 * screen and dies with the session, so a new login (password OR Google SSO)
 * re-challenges. Users without 2FA pass straight through.
 */
class RequireTwoFactorChallenge
{
    /**
     * Routes reachable while authenticated but not yet challenged — the
     * challenge screen itself and the ways out. Everything else is gated.
     */
    private const EXEMPT_ROUTES = [
        'two-factor.challenge',
        'two-factor.verify',
        'logout',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasTwoFactorEnabled()) {
            return $next($request);
        }

        if ($request->session()->get('auth.two_factor_confirmed') === true) {
            return $next($request);
        }

        if ($request->routeIs(self::EXEMPT_ROUTES)) {
            return $next($request);
        }

        return redirect()->route('two-factor.challenge');
    }
}
