<?php

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

        session(['google_return_url' => $request->get('return_url', route('settings.integrations'))]);

        $state = bin2hex(random_bytes(32));
        session(['google_oauth_state' => $state]);

        $params = http_build_query([
            'client_id' => $clientId,
            'redirect_uri' => config('services.google.redirect_uri'),
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

        abort_unless($request->get('state') === session()->pull('google_oauth_state'), 403);

        $code = $request->get('code');

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect_uri'),
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

        GoogleConnection::updateOrCreate(
            ['google_id' => $userInfo['id']],
            [
                'email' => $userInfo['email'],
                'name' => $userInfo['name'] ?? null,
                'avatar_url' => $userInfo['picture'] ?? null,
                'access_token' => encrypt($tokens['access_token']),
                'refresh_token' => encrypt($tokens['refresh_token'] ?? ''),
                'token_expires_at' => now()->addSeconds($tokens['expires_in']),
                'scopes' => ['analytics.readonly', 'webmasters.readonly'],
                'is_active' => true,
                'last_used_at' => now(),
            ]
        );

        return redirect(session('google_return_url', route('settings.integrations')))
            ->with('success', "Connected Google account: {$userInfo['email']}");
    }
}
