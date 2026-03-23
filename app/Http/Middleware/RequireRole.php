<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user) {
            abort(403);
        }

        $allowed = array_map(fn ($r) => UserRole::from($r), $roles);

        if (! in_array($user->role, $allowed, true)) {
            abort(403, 'You do not have permission to access this page.');
        }

        return $next($request);
    }
}
