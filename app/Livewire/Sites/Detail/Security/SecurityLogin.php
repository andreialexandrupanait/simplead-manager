<?php

namespace App\Livewire\Sites\Detail\Security;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Services\SecuritySettingsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SecurityLogin extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    // Brute force
    public int $maxAttempts = 5;
    public int $windowMinutes = 10;
    public int $blockDurationMinutes = 60;

    // Custom login URL
    public string $loginSlug = '';

    // 2FA
    public bool $twoFactorEnabled = false;

    public bool $isDirty = false;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->loadCurrentSettings();
    }

    protected function loadCurrentSettings(): void
    {
        $settings = SecuritySetting::where('site_id', $this->site->id)
            ->where('category', 'login')
            ->get()
            ->keyBy('setting_key');

        if ($bf = $settings->get('brute_force_protection')) {
            $val = $bf->setting_value ?? [];
            $this->maxAttempts = $val['max_attempts'] ?? 5;
            $this->windowMinutes = $val['window_minutes'] ?? 10;
            $this->blockDurationMinutes = $val['block_duration_minutes'] ?? 60;
        }

        if ($login = $settings->get('custom_login_url')) {
            $this->loginSlug = $login->setting_value['slug'] ?? '';
        }

        if ($twofa = $settings->get('two_factor_auth')) {
            $this->twoFactorEnabled = $twofa->is_enabled;
        }
    }

    #[Computed]
    public function loginSettings()
    {
        return $this->site->securitySettings()
            ->where('category', 'login')
            ->get()
            ->keyBy('setting_key');
    }

    public function updated($property): void
    {
        if ($property === 'isDirty') {
            return;
        }

        $this->isDirty = true;
    }

    public function save(): void
    {
        $this->validate([
            'maxAttempts' => 'required|integer|min:1|max:100',
            'windowMinutes' => 'required|integer|min:1|max:1440',
            'blockDurationMinutes' => 'required|integer|min:1|max:43200',
            'loginSlug' => 'nullable|string|max:50|alpha_dash',
        ]);

        $service = app(SecuritySettingsService::class);

        $service->applySetting(
            $this->site,
            'login',
            'brute_force_protection',
            [
                'max_attempts' => $this->maxAttempts,
                'window_minutes' => $this->windowMinutes,
                'block_duration_minutes' => $this->blockDurationMinutes,
            ],
            true,
        );

        $loginEnabled = !empty($this->loginSlug);

        $service->applySetting(
            $this->site,
            'login',
            'custom_login_url',
            ['slug' => $this->loginSlug],
            $loginEnabled,
        );

        $this->isDirty = false;
        unset($this->loginSettings);
        session()->flash('login-saved', 'Login protection settings saved.');
        $this->redirect(route('sites.security.login', $this->site), navigate: false);
    }

    public function toggleTwoFactor(): void
    {
        $this->twoFactorEnabled = !$this->twoFactorEnabled;

        app(SecuritySettingsService::class)->applySetting(
            $this->site,
            'login',
            'two_factor_auth',
            ['enabled' => $this->twoFactorEnabled],
            $this->twoFactorEnabled,
        );

        unset($this->loginSettings);
    }

    public function render()
    {
        return view('livewire.sites.detail.security.security-login')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Login Protection',
            ]);
    }
}
