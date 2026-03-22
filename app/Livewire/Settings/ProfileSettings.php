<?php

namespace App\Livewire\Settings;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithFileUploads;
use PragmaRX\Google2FA\Google2FA;
use Symfony\Component\HttpFoundation\StreamedResponse;
use ZipArchive;

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
            'email' => 'required|email|max:255|unique:users,email,'.Auth::id(),
            'timezone' => 'required|timezone',
            'language' => 'required|in:en,ro',
            'avatar' => 'nullable|image|mimes:jpeg,jpg,png,gif,webp|max:2048|dimensions:max_width=1000,max_height=1000',
        ]);

        $user = Auth::user();
        $user->name = $this->name;
        $user->email = $this->email;
        $user->timezone = $this->timezone;
        $user->language = $this->language;

        if ($this->avatar) {
            $path = $this->avatar->storeAs('avatars', uniqid('avatar_').'.'.$this->avatar->getClientOriginalExtension(), 'public');
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

        if (! Hash::check($this->currentPassword, Auth::user()->password)) {
            $this->addError('currentPassword', 'The current password is incorrect.');

            return;
        }

        Auth::user()->update([
            'password' => $this->newPassword,
        ]);

        $this->reset('currentPassword', 'newPassword', 'newPasswordConfirmation');

        $this->dispatch('notify', type: 'success', message: 'Password changed successfully.');
    }

    public string $deleteAccountPassword = '';

    public function deleteAccount(): void
    {
        $this->validate([
            'deleteAccountPassword' => 'required',
        ]);

        if (! Hash::check($this->deleteAccountPassword, Auth::user()->password)) {
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

    public function enableTwoFactor(): void
    {
        $google2fa = new Google2FA;
        $this->pendingTwoFactorSecret = $google2fa->generateSecretKey();

        $qrCodeUrl = $google2fa->getQRCodeUrl(
            config('app.name'),
            Auth::user()->email,
            $this->pendingTwoFactorSecret,
        );

        $renderer = new ImageRenderer(
            new RendererStyle(200),
            new SvgImageBackEnd,
        );
        $writer = new Writer($renderer);
        $svg = $writer->writeString($qrCodeUrl);

        // Sanitize: ensure output is a valid SVG and strip any script elements
        if (str_contains($svg, '<svg') && ! preg_match('/<script/i', $svg)) {
            $this->twoFactorQrSvg = $svg;
        } else {
            $this->twoFactorQrSvg = null;
        }

        $this->showingQrCode = true;
        $this->showingRecoveryCodes = false;
        $this->twoFactorCode = '';
    }

    public function confirmTwoFactor(): void
    {
        $this->validate([
            'twoFactorCode' => 'required|digits:6',
        ]);

        $google2fa = new Google2FA;

        if (! $google2fa->verifyKey($this->pendingTwoFactorSecret, $this->twoFactorCode)) {
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

    public function exportData(): StreamedResponse
    {
        $user = Auth::user();
        $zipPath = storage_path('app/temp/data-export-'.uniqid().'.zip');

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
            $this->dispatch('notify', type: 'error', message: 'Failed to create export archive.');

            return response()->noContent();
        }

        // User profile
        $zip->addFromString('profile.json', json_encode([
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => $user->timezone,
            'language' => $user->language,
            'role' => $user->role?->value ?? $user->role,
            'created_at' => $user->created_at?->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Sites
        $sites = $user->sites()->select('id', 'name', 'url', 'status', 'created_at')->get();
        $zip->addFromString('sites.json', json_encode(
            $sites->map(fn ($s) => [
                'name' => $s->name,
                'url' => $s->url,
                'status' => $s->status,
                'created_at' => $s->created_at?->toIso8601String(),
            ])->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ));

        // Reports metadata (not PDFs)
        $siteIds = $sites->pluck('id');
        $reports = \App\Models\Report::whereIn('site_id', $siteIds)
            ->select('id', 'site_id', 'title', 'period_start', 'period_end', 'trigger', 'status', 'created_at')
            ->get();
        $zip->addFromString('reports.json', json_encode(
            $reports->map(fn ($r) => [
                'title' => $r->title,
                'period_start' => $r->period_start?->toDateString(),
                'period_end' => $r->period_end?->toDateString(),
                'trigger' => $r->trigger,
                'status' => $r->status,
                'created_at' => $r->created_at?->toIso8601String(),
            ])->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ));

        // Activity logs
        $logs = $user->activityLogs()
            ->select('id', 'action', 'description', 'created_at')
            ->orderByDesc('created_at')
            ->limit(5000)
            ->get();
        $zip->addFromString('activity-logs.json', json_encode(
            $logs->map(fn ($l) => [
                'action' => $l->action,
                'description' => $l->description,
                'created_at' => $l->created_at?->toIso8601String(),
            ])->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
        ));

        $zip->close();

        $fileName = 'my-data-'.now()->format('Y-m-d').'.zip';

        return response()->download($zipPath, $fileName)->deleteFileAfterSend(true);
    }

    public function render()
    {
        return view('livewire.settings.profile-settings')
            ->layout('components.layouts.app', ['title' => 'Profile']);
    }
}
