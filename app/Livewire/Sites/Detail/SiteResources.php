<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\CheckResourceUsage;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\ResourceMonitorService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteResources extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function latestCheck()
    {
        return $this->site->latestResourceCheck;
    }

    #[Computed]
    public function history()
    {
        return app(ResourceMonitorService::class)->getHistory($this->site, 30);
    }

    #[Computed]
    public function thresholdViolations()
    {
        if (!$this->latestCheck) {
            return [];
        }

        return app(ResourceMonitorService::class)->checkThresholds($this->latestCheck);
    }

    public function checkNow(): void
    {
        CheckResourceUsage::dispatch($this->site);
        session()->flash('success', 'Resource check dispatched.');
        unset($this->latestCheck, $this->history, $this->thresholdViolations);
    }

    public function render()
    {
        return view('livewire.sites.detail.site-resources')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Resources',
            ]);
    }
}
