<?php

namespace App\Livewire\Dashboard;

use App\Models\AnalyticsCache;
use App\Models\SearchConsoleCache;
use App\Models\Site;
use Livewire\Component;

class SiteComparison extends Component
{
    public array $selectedSiteIds = [];
    public string $metric = 'analytics';
    public string $dateRange = '28d';

    public function addSite(int $siteId): void
    {
        if (count($this->selectedSiteIds) >= 4) {
            $this->dispatch('notify', type: 'error', message: 'Maximum 4 sites can be compared.');
            return;
        }

        if (!in_array($siteId, $this->selectedSiteIds)) {
            $this->selectedSiteIds[] = $siteId;
        }
    }

    public function removeSite(int $siteId): void
    {
        $this->selectedSiteIds = array_values(array_filter($this->selectedSiteIds, fn($id) => $id !== $siteId));
    }

    public function setMetric(string $metric): void
    {
        $this->metric = $metric;
    }

    public function setDateRange(string $range): void
    {
        $this->dateRange = $range;
    }

    public function render()
    {
        $allSites = Site::orderBy('name')->get();
        $comparisonData = [];
        $colors = ['#8D5CF5', '#06b6d4', '#f59e0b', '#ef4444'];

        foreach ($this->selectedSiteIds as $index => $siteId) {
            $site = $allSites->firstWhere('id', $siteId);
            if (!$site) continue;

            $entry = [
                'site' => $site,
                'color' => $colors[$index] ?? '#9ca3af',
                'metrics' => null,
                'timeSeries' => [],
            ];

            if ($this->metric === 'analytics') {
                $cache = AnalyticsCache::where('site_id', $siteId)
                    ->where('date_range', $this->dateRange)
                    ->latest('fetched_at')
                    ->first();

                if ($cache) {
                    $entry['metrics'] = $cache->data['overview'] ?? null;
                    $entry['timeSeries'] = $cache->data['users_over_time'] ?? [];
                }
            } else {
                $cache = SearchConsoleCache::where('site_id', $siteId)
                    ->where('date_range', $this->dateRange)
                    ->where('data_type', 'overview')
                    ->latest('fetched_at')
                    ->first();

                if ($cache) {
                    $entry['metrics'] = $cache->data;
                }

                $timeCache = SearchConsoleCache::where('site_id', $siteId)
                    ->where('date_range', $this->dateRange)
                    ->where('data_type', 'performance_over_time')
                    ->latest('fetched_at')
                    ->first();

                if ($timeCache) {
                    $entry['timeSeries'] = $timeCache->data ?? [];
                }
            }

            $comparisonData[] = $entry;
        }

        return view('livewire.dashboard.site-comparison', [
            'allSites' => $allSites,
            'comparisonData' => $comparisonData,
            'colors' => $colors,
        ])->layout('components.layouts.app', [
            'title' => 'Site Comparison',
        ]);
    }
}
