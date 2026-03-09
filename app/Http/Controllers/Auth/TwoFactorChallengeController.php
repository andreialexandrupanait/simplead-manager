<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorChallengeController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        if (!$request->session()->has('2fa:user_id')) {
            return redirect()->route('login');
        }

        return view('auth.two-factor-challenge');
    }

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'code' => 'required|string',
        ]);

        $userId = $request->session()->get('2fa:user_id');
        $remember = $request->session()->get('2fa:remember', false);

        if (!$userId) {
            return redirect()->route('login');
        }

        $user = User::find($userId);

        if (!$user) {
            return redirect()->route('login');
        }

        $code = $request->input('code');

        // Try TOTP code first
        if (strlen($code) === 6 && ctype_digit($code)) {
            $google2fa = new Google2FA();

            if ($google2fa->verifyKey($user->two_factor_secret, $code)) {
                return $this->loginUser($request, $user, $remember);
            }
        }

        // Try recovery code
        $recoveryCodes = $user->two_factor_recovery_codes ?? [];

        if (in_array($code, $recoveryCodes)) {
            // Remove used recovery code
            $user->update([
                'two_factor_recovery_codes' => array_values(array_diff($recoveryCodes, [$code])),
            ]);

            return $this->loginUser($request, $user, $remember);
        }

        return back()->withErrors(['code' => 'The provided code is invalid.']);
    }

    private function loginUser(Request $request, User $user, bool $remember): RedirectResponse
    {
        Auth::login($user, $remember);

        $request->session()->forget(['2fa:user_id', '2fa:remember']);
        $request->session()->regenerate();

        ActivityLogger::userLogin($user);

        return redirect()->intended(route('dashboard'));
    }
}
