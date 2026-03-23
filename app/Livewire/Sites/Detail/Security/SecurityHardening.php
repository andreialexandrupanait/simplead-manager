<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Security;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\SecuritySettingsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SecurityHardening extends Component
{
    use WithSiteAuthorization;

    public const RECOMMENDED_HARDENING = [
        'disable_theme_editor',
        'disable_user_enumeration',
        'hide_wp_version',
        'restrict_xmlrpc',
        'security_headers',
        'block_application_passwords',
    ];

    public const RECOMMENDED_HTACCESS = [
        'block_default_files',
        'block_readme_access',
        'block_debug_log',
        'disable_directory_listing',
    ];

    public Site $site;

    public array $hardeningToggles = [];

    public array $htaccessToggles = [];

    public array $settingStatuses = [];

    public bool $isDirty = false;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->loadCurrentState();
    }

    protected function loadCurrentState(): void
    {
        $settings = $this->site->securitySettings()
            ->whereIn('category', ['hardening', 'htaccess'])
            ->get()
            ->keyBy('setting_key');

        foreach (SecuritySettingsService::VALID_SETTING_KEYS['hardening'] as $key) {
            $this->hardeningToggles[$key] = $settings->get($key)?->is_enabled ?? false;
            $this->settingStatuses[$key] = $settings->get($key)?->status;
        }
        foreach (SecuritySettingsService::VALID_SETTING_KEYS['htaccess'] as $key) {
            $this->htaccessToggles[$key] = $settings->get($key)?->is_enabled ?? false;
            $this->settingStatuses[$key] = $settings->get($key)?->status;
        }
    }

    public function toggleSetting(string $category, string $key): void
    {
        if ($category === 'hardening' && array_key_exists($key, $this->hardeningToggles)) {
            $this->hardeningToggles[$key] = ! $this->hardeningToggles[$key];
        } elseif ($category === 'htaccess' && array_key_exists($key, $this->htaccessToggles)) {
            $this->htaccessToggles[$key] = ! $this->htaccessToggles[$key];
        } else {
            return;
        }

        $this->isDirty = true;
    }

    public function enableRecommended(): void
    {
        foreach (self::RECOMMENDED_HARDENING as $key) {
            $this->hardeningToggles[$key] = true;
        }
        foreach (self::RECOMMENDED_HTACCESS as $key) {
            $this->htaccessToggles[$key] = true;
        }
        $this->isDirty = true;
    }

    #[Computed]
    public function allRecommendedEnabled(): bool
    {
        foreach (self::RECOMMENDED_HARDENING as $key) {
            if (! ($this->hardeningToggles[$key] ?? false)) {
                return false;
            }
        }
        foreach (self::RECOMMENDED_HTACCESS as $key) {
            if (! ($this->htaccessToggles[$key] ?? false)) {
                return false;
            }
        }

        return true;
    }

    public function verifySettings(): void
    {
        try {
            $api = new \App\Services\WordPressApiService($this->site);
            $response = $api->request('GET', '/security-state');

            if (! $response->successful()) {
                session()->flash('verify-error', 'Could not reach site (HTTP '.$response->status().')');
                $this->redirect(route('sites.security.hardening', $this->site), navigate: false);

                return;
            }

            $data = $response->json();
            $hardeningState = $data['hardening']['state'] ?? [];
            $htaccessActive = $data['htaccess']['active_sections'] ?? [];

            $settings = $this->site->securitySettings()
                ->whereIn('category', ['hardening', 'htaccess'])
                ->where('is_enabled', true)
                ->get();

            $verified = 0;
            $mismatches = 0;
            $now = now();

            foreach ($settings as $setting) {
                $key = $setting->setting_key;
                $cat = $setting->category->value ?? $setting->category;

                $active = $cat === 'hardening'
                    ? ! empty($hardeningState[$key]['active'])
                    : ! empty($htaccessActive[$key]);

                if ($active) {
                    $setting->update(['applied_at' => $now, 'failed_at' => null, 'failure_reason' => null]);
                    $verified++;
                } else {
                    $setting->update(['failed_at' => $now, 'failure_reason' => 'Not active on WordPress']);
                    $mismatches++;
                }
            }

            if ($mismatches > 0) {
                app(SecuritySettingsService::class)->pushToPlugin($this->site);
                session()->flash('success', "{$verified} verified, {$mismatches} mismatches — re-push triggered.");
            } else {
                session()->flash('success', "All {$verified} settings verified on WordPress.");
            }

            $service = app(SecuritySettingsService::class);
            $this->site->update(['security_hardening_score' => $service->getSecurityScore($this->site)]);
        } catch (\Exception $e) {
            \Log::error('Verify security settings failed', ['site' => $this->site->id, 'error' => $e->getMessage()]);
            session()->flash('verify-error', 'Verification failed: '.$e->getMessage());
        }

        $this->loadCurrentState();
        $this->redirect(route('sites.security.hardening', $this->site), navigate: false);
    }

    public function save(): void
    {
        $service = app(SecuritySettingsService::class);

        foreach ($this->hardeningToggles as $key => $enabled) {
            $service->applySetting($this->site, 'hardening', $key, ['enabled' => $enabled], $enabled);
        }
        foreach ($this->htaccessToggles as $key => $enabled) {
            $service->applySetting($this->site, 'htaccess', $key, ['enabled' => $enabled], $enabled);
        }

        $this->isDirty = false;
        $this->loadCurrentState();

        session()->flash('success', 'Security settings saved. Changes will be applied shortly.');
        $this->redirect(route('sites.security.hardening', $this->site), navigate: false);
    }

    public function render()
    {
        return view('livewire.sites.detail.security.security-hardening')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Hardening',
            ]);
    }
}
