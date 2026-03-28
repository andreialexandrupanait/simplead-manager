<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        $hashedToken = hash('sha256', $token);
        $accessToken = PersonalAccessToken::where('token', $hashedToken)->first();

        if (! $accessToken || $accessToken->isExpired()) {
            return response()->json(['error' => 'Invalid or expired token'], 401);
        }

        $accessToken->update(['last_used_at' => now()]);

        $request->setUserResolver(fn () => $accessToken->user);
        $request->attributes->set('personal_access_token', $accessToken);

        return $next($request);
    }
}
