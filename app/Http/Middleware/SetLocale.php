<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $locale = $user->language ?? 'en';

            if (in_array($locale, ['en', 'ro'])) {
                app()->setLocale($locale);
                Carbon::setLocale($locale);
            }
        }

        return $next($request);
    }
}
