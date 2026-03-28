<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;

class GoogleSsoController extends Controller
{
    public function redirect()
    {
        /** @var \Laravel\Socialite\Two\GoogleProvider $driver */
        $driver = Socialite::driver('google');

        return $driver->scopes(['openid', 'profile', 'email'])->redirect();
    }

    public function callback()
    {
        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (\Throwable $e) {
            return redirect()->route('login')->with('error', 'Google authentication failed.');
        }

        // Find existing user by google_id or email
        $user = User::where('google_id', $googleUser->getId())
            ->orWhere('email', $googleUser->getEmail())
            ->first();

        if ($user) {
            // Link Google account if not already linked
            if (! $user->google_id) {
                $user->update(['google_id' => $googleUser->getId()]);
            }

            Auth::login($user, remember: true);

            return redirect('/');
        }

        // No existing user — only allow if SSO registration is enabled
        $ssoRegistration = app(\App\Services\SettingsService::class)->get('sso_auto_register', false);

        if (! $ssoRegistration) {
            return redirect()->route('login')->with('error', 'No account found for this email. Ask your administrator for an invitation.');
        }

        // Create new user
        $user = User::create([
            'name' => $googleUser->getName(),
            'email' => $googleUser->getEmail(),
            'google_id' => $googleUser->getId(),
            'password' => bcrypt(\Illuminate\Support\Str::random(32)),
            'email_verified_at' => now(),
            'role' => 'viewer',
        ]);

        Auth::login($user, remember: true);

        return redirect('/');
    }
}
