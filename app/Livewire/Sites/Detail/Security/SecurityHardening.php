<?php

namespace App\Livewire\Sites\Detail\Security;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\SecuritySettingsService;
use Livewire\Component;

class SecurityHardening extends Component
{
    use WithSiteAuthorization;

    public Site $site;
    public array $hardeningToggles = [];
    public array $htaccessToggles = [];
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
        }
        foreach (SecuritySettingsService::VALID_SETTING_KEYS['htaccess'] as $key) {
            $this->htaccessToggles[$key] = $settings->get($key)?->is_enabled ?? false;
        }
    }

    public function toggleSetting(string $category, string $key): void
    {
        if ($category === 'hardening' && array_key_exists($key, $this->hardeningToggles)) {
            $this->hardeningToggles[$key] = !$this->hardeningToggles[$key];
        } elseif ($category === 'htaccess' && array_key_exists($key, $this->htaccessToggles)) {
            $this->htaccessToggles[$key] = !$this->htaccessToggles[$key];
        } else {
            return;
        }

        $this->isDirty = true;
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
                'title' => $this->site->name . ' — Hardening',
            ]);
    }
}
