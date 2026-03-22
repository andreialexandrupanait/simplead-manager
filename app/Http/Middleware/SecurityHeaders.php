<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Generate a CSP nonce for this request
        $nonce = Str::random(32);
        app()->instance('csp-nonce', $nonce);
        if (method_exists(Livewire::class, 'setScriptNonce')) {
            Livewire::setScriptNonce($nonce);
        }

        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-XSS-Protection', '1; mode=block');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}' 'unsafe-eval'",
            "style-src 'self' 'unsafe-inline'",
            "img-src 'self' data: blob: https:",
            "font-src 'self' data:",
            "connect-src 'self'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "base-uri 'self'",
        ]));

        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        return $response;
    }
}
