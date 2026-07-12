<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentSite
{
    public function handle(Request $request, Closure $next): Response
    {
        $routeSite = $request->route('site');

        if ($routeSite === null) {
            return $next($request);
        }

        $site = $routeSite instanceof Site ? $routeSite : Site::find($routeSite);

        // Unknown id — return 404 (also avoids confirming existence).
        if (! $site instanceof Site) {
            abort(404);
        }

        $user = $request->user();

        // Authorize before exposing the site downstream. Without this any
        // authenticated user could set another tenant's site as the current
        // context (cross-tenant IDOR). Admins short-circuit inside
        // canAccessSite(). 403 matches the app-wide convention for foreign-site
        // access (see SitePolicy / report download auth); an unknown id already
        // 404s at route-model binding, so existence is not additionally leaked
        // here. Guests are left to the route's auth middleware; we simply don't
        // set any site context for them.
        if ($user !== null) {
            if (! $user->canAccessSite($site)) {
                abort(403);
            }

            view()->share('siteContext', $site);
            $request->merge(['currentSite' => $site]);
        }

        return $next($request);
    }
}
