<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\RunSeoCheck;
use App\Models\Site;
use App\Services\SeoService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteSeo extends Component
{
    public Site $site;

    public function mount(Site $site): void
    {
        $this->site = $site;
    }

    #[Computed]
    public function latestCheck()
    {
        return $this->site->latestSeoCheck;
    }

    #[Computed]
    public function recommendations()
    {
        if (!$this->latestCheck) {
            return [];
        }

        return app(SeoService::class)->getRecommendations($this->latestCheck);
    }

    #[Computed]
    public function history()
    {
        return $this->site->seoChecks()
            ->orderByDesc('checked_at')
            ->limit(10)
            ->get();
    }

    public function checkNow(): void
    {
        RunSeoCheck::dispatch($this->site);
        session()->flash('success', 'SEO check dispatched.');
        unset($this->latestCheck, $this->recommendations, $this->history);
    }

    public function render()
    {
        return view('livewire.sites.detail.site-seo')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — SEO',
            ]);
    }
}
