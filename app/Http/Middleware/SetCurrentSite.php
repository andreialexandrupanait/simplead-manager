<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;

class SetCurrentSite
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->route('site')) {
            $site = $request->route('site');

            if (! $site instanceof Site) {
                $site = Site::findOrFail($site);
            }

            view()->share('siteContext', $site);
            $request->merge(['currentSite' => $site]);
        }

        return $next($request);
    }
}
