<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\FetchAnalyticsData;
use App\Models\AnalyticsCache;
use App\Models\AnalyticsConnection;
use App\Models\GoogleConnection;
use App\Models\Site;
use App\Services\GoogleAnalyticsService;
use Livewire\Component;

class SiteAnalytics extends Component
{
    public Site $site;
    public string $dateRange = '28d';
    public array $availableProperties = [];

    public function mount(Site $site): void
    {
        $this->site = $site;
    }

    public function setDateRange(string $range): void
    {
        $this->dateRange = $range;

        $connection = $this->site->analyticsConnection;
        if (!$connection) return;

        $cache = AnalyticsCache::where('site_id', $this->site->id)
            ->where('date_range', $this->dateRange)
            ->latest('fetched_at')
            ->first();

        if (!$cache || $cache->expires_at->isPast()) {
            FetchAnalyticsData::dispatch($this->site, $this->dateRange);
            session()->flash('analytics-refreshing', true);
        }
    }

    public function refreshData(): void
    {
        $connection = $this->site->analyticsConnection;
        if (!$connection || !$connection->is_active) return;

        FetchAnalyticsData::dispatch($this->site, $this->dateRange);
        session()->flash('analytics-refreshing', true);
    }

    public function connectAnalytics(): void
    {
        $googleConnection = GoogleConnection::where('is_active', true)->first();

        if (!$googleConnection) {
            $this->redirect(route('google.auth', ['return_url' => route('sites.analytics', $this->site)]));
            return;
        }

        try {
            $service = new GoogleAnalyticsService($googleConnection);
            $this->availableProperties = $service->listProperties();
        } catch (\Exception $e) {
            session()->flash('error', 'Failed to list properties: ' . $e->getMessage());
        }
    }

    public function selectProperty(int $index): void
    {
        $property = $this->availableProperties[$index] ?? null;
        if (!$property) return;

        $googleConnection = GoogleConnection::where('is_active', true)->first();
        if (!$googleConnection) return;

        AnalyticsConnection::updateOrCreate(
            ['site_id' => $this->site->id],
            [
                'google_connection_id' => $googleConnection->id,
                'property_id' => $property['property_id'],
                'property_name' => $property['property_name'],
                'is_active' => true,
            ]
        );

        $this->availableProperties = [];
        $this->site->load('analyticsConnection');

        FetchAnalyticsData::dispatch($this->site, $this->dateRange);
        session()->flash('success', "Connected to {$property['property_name']}. Data is being fetched.");
    }

    public function disconnectAnalytics(): void
    {
        $this->site->analyticsConnection?->delete();
        AnalyticsCache::where('site_id', $this->site->id)->delete();
        $this->site->unsetRelation('analyticsConnection');

        session()->flash('success', 'Analytics disconnected.');
    }

    public function render()
    {
        $connection = $this->site->analyticsConnection;
        $cache = null;
        $overview = null;
        $usersOverTime = [];
        $trafficSources = [];
        $topPages = [];
        $devices = [];
        $countries = [];
        $cities = [];

        if ($connection && $connection->is_active) {
            $cache = AnalyticsCache::where('site_id', $this->site->id)
                ->where('date_range', $this->dateRange)
                ->latest('fetched_at')
                ->first();

            if ($cache) {
                $data = $cache->data;
                $overview = $data['overview'] ?? null;
                $usersOverTime = $data['users_over_time'] ?? [];
                $trafficSources = $data['traffic_sources'] ?? [];
                $topPages = $data['top_pages'] ?? [];
                $devices = $data['devices'] ?? [];
                $countries = $data['countries'] ?? [];
                $cities = $data['cities'] ?? [];
            }
        }

        $googleConnections = GoogleConnection::where('is_active', true)->get();

        return view('livewire.sites.detail.site-analytics', [
            'connection' => $connection,
            'cache' => $cache,
            'overview' => $overview,
            'usersOverTime' => $usersOverTime,
            'trafficSources' => $trafficSources,
            'topPages' => $topPages,
            'devices' => $devices,
            'countries' => $countries,
            'cities' => $cities,
            'googleConnections' => $googleConnections,
        ])->layout('components.layouts.app', [
            'siteContext' => $this->site,
            'title' => $this->site->name . ' — Analytics',
        ]);
    }
}
