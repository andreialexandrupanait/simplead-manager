<?php

namespace App\Livewire\Sites\Detail;

use App\Models\AnalyticsCache;
use App\Models\SearchConsoleCache;
use App\Models\Site;
use Livewire\Component;

class SiteOverview extends Component
{
    public Site $site;

    public function mount(Site $site): void
    {
        $this->site = $site;
    }

    public function getAnalyticsSummaryProperty(): ?array
    {
        $cache = AnalyticsCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->first();

        if (!$cache) return null;

        return $cache->data['overview'] ?? null;
    }

    public function getSearchConsoleSummaryProperty(): ?array
    {
        $cache = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->where('data_type', 'overview')
            ->first();

        if (!$cache) return null;

        return $cache->data;
    }

    public function render()
    {
        return view('livewire.sites.detail.site-overview', [
            'analyticsSummary' => $this->analyticsSummary,
            'searchConsoleSummary' => $this->searchConsoleSummary,
        ])->layout('components.layouts.app', [
            'siteContext' => $this->site,
            'title' => $this->site->name . ' — Overview',
        ]);
    }
}
