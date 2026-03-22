<?php

namespace App\Livewire\Sites\Detail\Tweaks;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\SiteTweaksSettingsService;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

class TweaksOverview extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function settingsByCategory(): Collection
    {
        return app(SiteTweaksSettingsService::class)->getSettingsForSite($this->site);
    }

    #[Computed]
    public function enabledCount(): int
    {
        return $this->site->securitySettings()
            ->whereIn('category', SiteTweaksSettingsService::TWEAK_CATEGORIES)
            ->where('is_enabled', true)
            ->count();
    }

    #[Computed]
    public function appliedCount(): int
    {
        return $this->site->securitySettings()
            ->whereIn('category', SiteTweaksSettingsService::TWEAK_CATEGORIES)
            ->where('is_enabled', true)
            ->whereNotNull('applied_at')
            ->whereNull('failed_at')
            ->count();
    }

    #[Computed]
    public function failedCount(): int
    {
        return $this->site->securitySettings()
            ->whereIn('category', SiteTweaksSettingsService::TWEAK_CATEGORIES)
            ->whereNotNull('failed_at')
            ->count();
    }

    public function render()
    {
        return view('livewire.sites.detail.tweaks.tweaks-overview')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Site Tweaks',
            ]);
    }
}
