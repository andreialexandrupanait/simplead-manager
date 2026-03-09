<?php

namespace App\Livewire\Sites\Detail\Security;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\SecuritySetting;
use App\Models\Site;
use App\Services\SecuritySettingsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SecurityCaptcha extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public string $provider = 'none';
    public string $siteKey = '';
    public string $secretKey = '';

    public bool $enableLogin = true;
    public bool $enableRegister = true;
    public bool $enableComments = false;
    public bool $enableResetPassword = true;

    public bool $hasExistingKeys = false;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->loadCurrentSettings();
    }

    protected function loadCurrentSettings(): void
    {
        $setting = SecuritySetting::where('site_id', $this->site->id)
            ->where('category', 'captcha')
            ->where('setting_key', 'captcha_config')
            ->first();

        if ($setting) {
            $val = $setting->setting_value ?? [];
            $this->provider = $val['provider'] ?? 'none';
            $this->enableLogin = $val['forms']['login'] ?? true;
            $this->enableRegister = $val['forms']['register'] ?? true;
            $this->enableComments = $val['forms']['comments'] ?? false;
            $this->enableResetPassword = $val['forms']['reset_password'] ?? true;

            if (!empty($val['site_key'])) {
                $this->hasExistingKeys = true;
            }
        }
    }

    #[Computed]
    public function captchaSetting()
    {
        return SecuritySetting::where('site_id', $this->site->id)
            ->where('category', 'captcha')
            ->where('setting_key', 'captcha_config')
            ->first();
    }

    public function save(): void
    {
        $rules = ['provider' => 'required|in:none,recaptcha_v2,recaptcha_v3,hcaptcha,turnstile'];

        if ($this->provider !== 'none') {
            if (!$this->hasExistingKeys) {
                $rules['siteKey'] = 'required|string|max:255';
                $rules['secretKey'] = 'required|string|max:255';
            } else {
                $rules['siteKey'] = 'nullable|string|max:255';
                $rules['secretKey'] = 'nullable|string|max:255|required_with:siteKey';
            }
        }

        $this->validate($rules);

        $enabled = $this->provider !== 'none';

        $value = [
            'provider' => $this->provider,
            'forms' => [
                'login' => $this->enableLogin,
                'register' => $this->enableRegister,
                'comments' => $this->enableComments,
                'reset_password' => $this->enableResetPassword,
            ],
        ];

        if (!empty($this->siteKey) && !empty($this->secretKey)) {
            $value['site_key'] = $this->siteKey;
            $value['secret_key'] = encrypt($this->secretKey);
        } elseif ($this->hasExistingKeys) {
            $existing = $this->captchaSetting?->setting_value ?? [];
            $value['site_key'] = $existing['site_key'] ?? '';
            $value['secret_key'] = $existing['secret_key'] ?? '';
        }

        app(SecuritySettingsService::class)->applySetting(
            $this->site,
            'captcha',
            'captcha_config',
            $value,
            $enabled,
        );

        $this->siteKey = '';
        $this->secretKey = '';
        if ($enabled) {
            $this->hasExistingKeys = true;
        }

        unset($this->captchaSetting);
        session()->flash('captcha-saved', 'CAPTCHA settings saved.');
        $this->redirect(route('sites.security.captcha', $this->site), navigate: false);
    }

    public function render()
    {
        return view('livewire.sites.detail.security.security-captcha')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Captcha',
            ]);
    }
}
