<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\FetchSearchConsoleData;
use App\Models\GoogleConnection;
use App\Models\SearchConsoleCache;
use App\Models\SearchConsoleConnection;
use App\Models\Site;
use App\Services\GoogleSearchConsoleService;
use Livewire\Component;

class SiteSearchConsole extends Component
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

        $connection = $this->site->searchConsoleConnection;
        if (!$connection) return;

        $cache = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', $this->dateRange)
            ->where('data_type', 'overview')
            ->first();

        if (!$cache || $cache->expires_at->isPast()) {
            FetchSearchConsoleData::dispatch($this->site, $this->dateRange);
            session()->flash('gsc-refreshing', true);
        }
    }

    public function refreshData(): void
    {
        $connection = $this->site->searchConsoleConnection;
        if (!$connection || !$connection->is_active) return;

        FetchSearchConsoleData::dispatch($this->site, $this->dateRange);
        session()->flash('gsc-refreshing', true);
    }

    public function connectSearchConsole(): void
    {
        $googleConnection = GoogleConnection::where('is_active', true)->first();

        if (!$googleConnection) {
            $this->redirect(route('google.auth', ['return_url' => route('sites.search-console', $this->site)]));
            return;
        }

        try {
            $service = new GoogleSearchConsoleService($googleConnection);
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

        $propertyType = str_starts_with($property['site_url'], 'sc-domain:') ? 'domain' : 'url';

        SearchConsoleConnection::updateOrCreate(
            ['site_id' => $this->site->id],
            [
                'google_connection_id' => $googleConnection->id,
                'property_url' => $property['site_url'],
                'property_type' => $propertyType,
                'permission_level' => $property['permission_level'],
                'is_active' => true,
            ]
        );

        $this->availableProperties = [];
        $this->site->load('searchConsoleConnection');

        FetchSearchConsoleData::dispatch($this->site, $this->dateRange);
        session()->flash('success', "Connected to {$property['site_url']}. Data is being fetched.");
    }

    public function disconnectSearchConsole(): void
    {
        $this->site->searchConsoleConnection?->delete();
        SearchConsoleCache::where('site_id', $this->site->id)->delete();
        $this->site->unsetRelation('searchConsoleConnection');

        session()->flash('success', 'Search Console disconnected.');
    }

    public function render()
    {
        $connection = $this->site->searchConsoleConnection;
        $overview = null;
        $performanceOverTime = [];
        $queries = [];
        $pages = [];
        $countries = [];
        $devices = [];
        $cache = null;

        if ($connection && $connection->is_active) {
            $caches = SearchConsoleCache::where('site_id', $this->site->id)
                ->where('date_range', $this->dateRange)
                ->get()
                ->keyBy('data_type');

            $cache = $caches->get('overview');
            $overview = $cache?->data;
            $performanceOverTime = $caches->get('performance_over_time')?->data ?? [];
            $queries = $caches->get('queries')?->data ?? [];
            $pages = $caches->get('pages')?->data ?? [];
            $countries = $caches->get('countries')?->data ?? [];
            $devices = $caches->get('devices')?->data ?? [];
        }

        $googleConnections = GoogleConnection::where('is_active', true)->get();

        return view('livewire.sites.detail.site-search-console', [
            'connection' => $connection,
            'cache' => $cache,
            'overview' => $overview,
            'performanceOverTime' => $performanceOverTime,
            'queries' => $queries,
            'pages' => $pages,
            'countries' => $countries,
            'devices' => $devices,
            'googleConnections' => $googleConnections,
        ])->layout('components.layouts.app', [
            'siteContext' => $this->site,
            'title' => $this->site->name . ' — Search Console',
        ]);
    }
}
