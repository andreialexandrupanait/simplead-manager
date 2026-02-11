<?php

namespace App\Livewire\Settings;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use PragmaRX\Google2FA\Google2FA;

class ProfileSettings extends Component
{
    use WithFileUploads;

    // Profile
    public string $name = '';
    public string $email = '';
    public string $timezone = 'UTC';
    public string $language = 'en';
    public $avatar = null;

    // Password
    public string $currentPassword = '';
    public string $newPassword = '';
    public string $newPasswordConfirmation = '';

    // 2FA
    public bool $showingQrCode = false;
    public bool $showingRecoveryCodes = false;
    public string $twoFactorCode = '';
    public ?string $twoFactorQrSvg = null;
    #[Locked]
    public ?string $pendingTwoFactorSecret = null;
    public array $recoveryCodes = [];

    public function mount(): void
    {
        $user = Auth::user();
        $this->name = $user->name;
        $this->email = $user->email;
        $this->timezone = $user->timezone ?? 'UTC';
        $this->language = $user->language ?? 'en';

        if ($user->two_factor_enabled) {
            $this->recoveryCodes = $user->two_factor_recovery_codes ?? [];
        }
    }

    public function saveProfile(): void
    {
        $this->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email,' . Auth::id(),
            'timezone' => 'required|timezone',
            'language' => 'required|in:en,ro',
            'avatar' => 'nullable|image|max:2048',
        ]);

        $user = Auth::user();
        $user->name = $this->name;
        $user->email = $this->email;
        $user->timezone = $this->timezone;
        $user->language = $this->language;

        if ($this->avatar) {
            $path = $this->avatar->store('avatars', 'public');
            $user->avatar_path = $path;
        }

        $user->save();

        $this->dispatch('notify', type: 'success', message: 'Profile saved successfully.');
    }

    public function changePassword(): void
    {
        $this->validate([
            'currentPassword' => 'required',
            'newPassword' => ['required', 'string', Password::min(8), 'different:currentPassword'],
            'newPasswordConfirmation' => 'required|same:newPassword',
        ]);

        if (!Hash::check($this->currentPassword, Auth::user()->password)) {
            $this->addError('currentPassword', 'The current password is incorrect.');
            return;
        }

        Auth::user()->update([
            'password' => $this->newPassword,
        ]);

        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');

        $this->dispatch('notify', type: 'success', message: 'Password changed successfully.');
    }

    public function logoutOtherSessions(): void
    {
        DB::table('sessions')
            ->where('user_id', Auth::id())
            ->where('id', '!=', session()->getId())
            ->delete();

        $this->dispatch('notify', type: 'success', message: 'Other sessions have been logged out.');
    }

    public string $deleteAccountPassword = '';

    public function deleteAccount(): void
    {
        $this->validate([
            'deleteAccountPassword' => 'required',
        ]);

        if (!Hash::check($this->deleteAccountPassword, Auth::user()->password)) {
            $this->addError('deleteAccountPassword', 'The password is incorrect.');
            return;
        }

        $user = Auth::user();
        Auth::logout();
        $user->delete();

        session()->invalidate();
        session()->regenerateToken();

        $this->redirect('/login');
    }

    public function getSessionsProperty()
    {
        return DB::table('sessions')
            ->where('user_id', Auth::id())
            ->orderByDesc('last_activity')
            ->get()
            ->map(function ($session) {
                return (object) [
                    'id' => $session->id,
                    'ip_address' => $session->ip_address,
                    'user_agent' => $session->user_agent,
                    'last_activity' => \Carbon\Carbon::createFromTimestamp($session->last_activity)->diffForHumans(),
                    'is_current' => $session->id === session()->getId(),
                ];
            });
    }

    public function enableTwoFactor(): void
    {
        $google2fa = new Google2FA();
        $this->pendingTwoFactorSecret = $google2fa->generateSecretKey();

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            Auth::user()->email,
            $this->pendingTwoFactorSecret,
        );

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd(),
        );
        $writer = new Writer($renderer);
        $this->twoFactorQrSvg = $writer->writeString($qrCodeUrl);

        $this->showingQrCode = true;
        $this->showingRecoveryCodes = false;
        $this->twoFactorCode = '';
    }

    public function confirmTwoFactor(): void
    {
        $this->validate([
            'twoFactorCode' => 'required|digits:6',
        ]);

        $google2fa = new Google2FA();

        if (!$google2fa->verifyKey($this->pendingTwoFactorSecret, $this->twoFactorCode)) {
            $this->addError('twoFactorCode', 'The code is invalid. Please try again.');
            return;
        }

        $codes = collect(range(1, 8))->map(fn () => Str::random(10))->all();

        $user = Auth::user();
        $user->update([
            'two_factor_enabled' => true,
            'two_factor_secret' => $this->pendingTwoFactorSecret,
            'two_factor_recovery_codes' => $codes,
        ]);

        $this->recoveryCodes = $codes;
        $this->showingQrCode = false;
        $this->showingRecoveryCodes = true;
        $this->pendingTwoFactorSecret = null;
        $this->twoFactorQrSvg = null;
        $this->twoFactorCode = '';

        $this->dispatch('notify', type: 'success', message: 'Two-factor authentication enabled.');
    }

    public function disableTwoFactor(): void
    {
        Auth::user()->update([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ]);

        $this->showingQrCode = false;
        $this->showingRecoveryCodes = false;
        $this->recoveryCodes = [];

        $this->dispatch('notify', type: 'success', message: 'Two-factor authentication disabled.');
    }

    public function regenerateRecoveryCodes(): void
    {
        $codes = collect(range(1, 8))->map(fn () => Str::random(10))->all();

        Auth::user()->update([
            'two_factor_recovery_codes' => $codes,
        ]);

        $this->recoveryCodes = $codes;
        $this->showingRecoveryCodes = true;

        $this->dispatch('notify', type: 'success', message: 'Recovery codes regenerated.');
    }

    public function showRecoveryCodes(): void
    {
        $this->recoveryCodes = Auth::user()->two_factor_recovery_codes ?? [];
        $this->showingRecoveryCodes = true;
    }

    public function render()
    {
        return view('livewire.settings.profile-settings')
            ->layout('components.layouts.app', ['title' => 'Profile']);
    }
}
