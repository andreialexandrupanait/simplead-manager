<?php

namespace App\Http\Middleware;

use App\Models\Site;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateAgent
{
    public function handle(Request $request, Closure $next): Response
    {
        $siteToken = $request->route('site_token');

        if (! $siteToken) {
            return response()->json(['error' => 'Missing site token.'], 401);
        }

        // Find site by API key
        $site = Site::where('api_key', $siteToken)->first();

        if (! $site) {
            return response()->json(['error' => 'Invalid site token.'], 401);
        }

        // Verify HMAC-SHA256 signature
        $signature = $request->header('X-Signature');
        $timestamp = $request->header('X-Timestamp');

        if (! $signature || ! $timestamp) {
            return response()->json(['error' => 'Missing signature or timestamp.'], 401);
        }

        // Validate timestamp freshness (5 minute window)
        $requestTime = (int) $timestamp;
        if (abs(time() - $requestTime) > 300) {
            return response()->json(['error' => 'Request timestamp expired.'], 401);
        }

        // Compute expected signature
        $payload = $timestamp.'.'.$request->getContent();
        $expectedSignature = hash_hmac('sha256', $payload, $site->api_secret);

        if (! hash_equals($expectedSignature, $signature)) {
            return response()->json(['error' => 'Invalid signature.'], 401);
        }

        // Bind site to request, replacing the raw site_token with the resolved Site model
        $request->merge(['_site' => $site]);
        $request->route()->forgetParameter('site_token');
        $request->route()->setParameter('site', $site);

        return $next($request);
    }
}
