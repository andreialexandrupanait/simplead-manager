<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\GoogleConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class GoogleAuthController extends Controller
{
    public function redirect(Request $request)
    {
        $clientId = config('services.google.client_id');

        if (empty($clientId)) {
            return redirect()->route('settings.integrations')
                ->with('error', 'Google Client ID is not configured. Add your credentials in Settings > Integrations.');
        }

        $returnUrl = $request->get('return_url', route('settings.integrations'));
        $appHost = parse_url(config('app.url'), PHP_URL_HOST);
        $returnHost = parse_url($returnUrl, PHP_URL_HOST);

        // Only allow relative URLs or URLs matching the app's host
        if ($returnHost !== null && $returnHost !== $appHost) {
            $returnUrl = route('settings.integrations');
        }

        session(['google_return_url' => $returnUrl]);

        $state = bin2hex(random_bytes(32));
        session(['google_oauth_state' => $state]);

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => route('google.callback'),
            'response_type' => 'code',
            'scope' => implode(' ', [
                'openid',
                'email',
                'profile',
                'https://www.googleapis.com/auth/analytics.readonly',
                'https://www.googleapis.com/auth/webmasters.readonly',
            ]),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return redirect("https://accounts.google.com/o/oauth2/v2/auth?{$params}");
    }

    public function callback(Request $request)
    {
        if ($request->has('error')) {
            return redirect(session('google_return_url', route('settings.integrations')))
                ->with('error', 'Google authorization was cancelled.');
        }

        // CSRF protection: reject unless a non-empty session state exists AND
        // matches the returned state. Fail closed — the previous strict-equality
        // check passed when BOTH were null, letting a crafted /google/callback
        // link with no state param bypass it entirely (P1-48).
        $sessionState = session()->pull('google_oauth_state');
        $requestState = $request->get('state');

        abort_unless(
            is_string($sessionState) && $sessionState !== ''
                && is_string($requestState)
                && hash_equals($sessionState, $requestState),
            403,
        );

        $code = $request->get('code');

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => route('google.callback'),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if ($response->failed()) {
            return redirect(session('google_return_url', route('settings.integrations')))
                ->with('error', 'Failed to connect to Google.');
        }

        $tokens = $response->json();

        $userInfo = Http::withToken($tokens['access_token'])
            ->get('https://www.googleapis.com/oauth2/v2/userinfo')
            ->json();

        // Fail gracefully instead of 500ing if Google returns an unexpected
        // userinfo payload (P1-48 companion hardening).
        if (! is_array($userInfo) || empty($userInfo['id']) || empty($userInfo['email'])) {
            return redirect(session('google_return_url', route('settings.integrations')))
                ->with('error', 'Failed to read your Google account details. Please try again.');
        }

        $attributes = [
            'email' => $userInfo['email'],
            'name' => $userInfo['name'] ?? null,
            'avatar_url' => $userInfo['picture'] ?? null,
            'access_token' => encrypt($tokens['access_token']),
            'token_expires_at' => now()->addSeconds($tokens['expires_in']),
            'scopes' => ['analytics.readonly', 'webmasters.readonly'],
            'is_active' => true,
            'last_used_at' => now(),
        ];

        // Google only returns a refresh_token on the first consent; on re-auth it
        // is often omitted. Never overwrite a previously stored refresh token
        // with an empty value (P2-49) — that would wipe a working credential.
        $refreshToken = $tokens['refresh_token'] ?? null;
        if (is_string($refreshToken) && $refreshToken !== '') {
            $attributes['refresh_token'] = encrypt($refreshToken);
        }

        GoogleConnection::updateOrCreate(['google_id' => $userInfo['id']], $attributes);

        return redirect(session('google_return_url', route('settings.integrations')))
            ->with('success', "Connected Google account: {$userInfo['email']}");
    }
}
