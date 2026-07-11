<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Security;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\ModuleConfigService;
use App\Services\SecuritySettingsService;
use App\Services\SiteTweaksSettingsService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SecurityOverview extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function isModuleActive(): bool
    {
        return app(ModuleConfigService::class)->isModuleActive($this->site, 'security');
    }

    public function activateModule(): void
    {
        app(ModuleConfigService::class)->toggleModule($this->site, 'security', true);
        unset($this->isModuleActive);
    }

    #[Computed]
    public function securityScore(): ?int
    {
        return $this->site->security_hardening_score;
    }

    #[Computed]
    public function settingsByCategory(): Collection
    {
        return app(SecuritySettingsService::class)->getSettingsForSite($this->site);
    }

    #[Computed]
    public function tweakSettingsByCategory(): Collection
    {
        return app(SiteTweaksSettingsService::class)->getSettingsForSite($this->site);
    }

    #[Computed]
    public function lastSyncAt(): ?string
    {
        return $this->site->securitySettings()->max('applied_at');
    }

    public function render()
    {
        return view('livewire.sites.detail.security.security-overview')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Security',
            ]);
    }
}
