<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\FetchSearchConsoleData;
use App\Livewire\Traits\WithJobTracking;
use App\Models\GoogleConnection;
use App\Models\KeywordPosition;
use App\Models\SearchConsoleCache;
use App\Models\SearchConsoleConnection;
use App\Models\Site;
use App\Models\TrackedKeyword;
use App\Services\GoogleSearchConsoleService;
use Livewire\Component;

class SiteSearchConsole extends Component
{
    use WithJobTracking;

    public Site $site;
    public string $dateRange = '28d';
    public ?string $customStart = null;
    public ?string $customEnd = null;
    public array $availableProperties = [];
    public string $inspectUrl = '';
    public ?array $urlInspectionResult = null;

    // Drill-down state
    public array $drillDownResults = [];
    public string $drillDownLabel = '';
    public string $drillDownType = '';

    protected function jobTrackingKeys(): array
    {
        return ['fetch' => 'search-console-' . $this->site->id];
    }

    public function mount(Site $site): void
    {
        $this->site = $site;
        $this->initJobTracking();
    }

    public function setDateRange(string $range): void
    {
        $this->dateRange = $range;

        $connection = $this->site->searchConsoleConnection;
        if (!$connection) return;

        $cache = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', $this->dateRange)
            ->where('data_type', 'overview')
            ->latest('fetched_at')
            ->first();

        if (!$cache || $cache->expires_at->isPast()) {
            $this->dispatchTrackedJob('fetch', new FetchSearchConsoleData($this->site, $this->dateRange), 'Fetching Search Console data...');
        }
    }

    public function setCustomDateRange(string $start, string $end): void
    {
        $this->dateRange = 'custom';
        $this->customStart = $start;
        $this->customEnd = $end;

        $connection = $this->site->searchConsoleConnection;
        if (!$connection) return;

        $cache = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', 'custom')
            ->where('data_type', 'overview')
            ->where('start_date', $start)
            ->where('end_date', $end)
            ->latest('fetched_at')
            ->first();

        if (!$cache || $cache->expires_at->isPast()) {
            $this->dispatchTrackedJob('fetch', new FetchSearchConsoleData($this->site, 'custom', $start, $end), 'Fetching Search Console data...');
        }
    }

    public function refreshData(): void
    {
        $connection = $this->site->searchConsoleConnection;
        if (!$connection || !$connection->is_active) return;

        $this->dispatchTrackedJob('fetch', new FetchSearchConsoleData($this->site, $this->dateRange), 'Fetching Search Console data...');
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        // Data will refresh automatically on next render since it reads from cache
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

    public function inspectUrlAction(): void
    {
        $this->urlInspectionResult = null;

        $url = trim($this->inspectUrl);
        if (empty($url)) {
            $this->dispatch('notify', type: 'error', message: 'Please enter a URL to inspect.');
            return;
        }

        $connection = $this->site->searchConsoleConnection;
        if (!$connection || !$connection->is_active) return;

        $google = $connection->googleConnection;
        if (!$google || !$google->is_active) return;

        try {
            $service = new GoogleSearchConsoleService($google);
            $this->urlInspectionResult = $service->inspectUrl($connection->property_url, $url);
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'URL Inspection failed: ' . $e->getMessage());
        }
    }

    public function drillDown(string $type, string $value): void
    {
        $this->drillDownResults = [];
        $this->drillDownLabel = $value;
        $this->drillDownType = $type;

        $connection = $this->site->searchConsoleConnection;
        if (!$connection || !$connection->is_active) return;

        $google = $connection->googleConnection;
        if (!$google || !$google->is_active) return;

        [$startDate, $endDate] = $this->getDateRange();

        try {
            $service = new GoogleSearchConsoleService($google);
            $siteUrl = $connection->property_url;

            if ($type === 'query') {
                // Show pages for this query
                $this->drillDownResults = $service->getFilteredResults($siteUrl, $startDate, $endDate, 'query', $value, 'page', 20);
            } else {
                // Show queries for this page
                $this->drillDownResults = $service->getFilteredResults($siteUrl, $startDate, $endDate, 'page', $value, 'query', 20);
            }

            $this->dispatch('open-modal-sc-drilldown');
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Drill-down failed: ' . $e->getMessage());
        }
    }

    private function getDateRange(): array
    {
        $endDate = now()->subDays(3)->format('Y-m-d');

        $startDate = match ($this->dateRange) {
            '7d' => now()->subDays(10)->format('Y-m-d'),
            '28d' => now()->subDays(31)->format('Y-m-d'),
            '90d' => now()->subDays(93)->format('Y-m-d'),
            default => now()->subDays(31)->format('Y-m-d'),
        };

        return [$startDate, $endDate];
    }

    public function trackKeyword(string $keyword): void
    {
        $keyword = trim($keyword);
        if (empty($keyword)) return;

        TrackedKeyword::firstOrCreate([
            'site_id' => $this->site->id,
            'keyword' => $keyword,
        ]);

        $this->dispatch('notify', type: 'success', message: "Tracking keyword: {$keyword}");
    }

    public function untrackKeyword(int $id): void
    {
        TrackedKeyword::where('id', $id)
            ->where('site_id', $this->site->id)
            ->delete();

        $this->dispatch('notify', type: 'success', message: 'Keyword removed from tracking.');
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
        $overviewPrevious = null;
        $performanceOverTime = [];
        $queries = [];
        $pages = [];
        $countries = [];
        $devices = [];
        $searchAppearance = [];
        $sitemaps = [];
        $cache = null;
        $deltas = [];
        $insights = [];

        if ($connection && $connection->is_active) {
            $query = SearchConsoleCache::where('site_id', $this->site->id)
                ->where('date_range', $this->dateRange);

            if ($this->dateRange === 'custom' && $this->customStart && $this->customEnd) {
                $query->where('start_date', $this->customStart)->where('end_date', $this->customEnd);
            }

            $caches = $query->get()->keyBy('data_type');

            $cache = $caches->get('overview');
            $overview = $cache?->data;
            $overviewPrevious = $caches->get('overview_previous')?->data;
            $performanceOverTime = $caches->get('performance_over_time')?->data ?? [];
            $queries = $caches->get('queries')?->data ?? [];
            $pages = $caches->get('pages')?->data ?? [];
            $countries = $caches->get('countries')?->data ?? [];
            $devices = $caches->get('devices')?->data ?? [];
            $searchAppearance = $caches->get('search_appearance')?->data ?? [];
            $sitemaps = $caches->get('sitemaps')?->data ?? [];

            // Compute deltas and insights
            if ($overview && $overviewPrevious) {
                $deltas = $this->computeDeltas($overview, $overviewPrevious);
                $insights = $this->computeInsights($overview, $overviewPrevious);
            }
        }

        // Tracked keywords with recent positions
        $trackedKeywords = TrackedKeyword::where('site_id', $this->site->id)
            ->with(['positions' => function ($q) {
                $q->orderByDesc('date')->limit(30);
            }])
            ->get();

        $googleConnections = GoogleConnection::where('is_active', true)->get();

        return view('livewire.sites.detail.site-search-console', [
            'connection' => $connection,
            'cache' => $cache,
            'overview' => $overview,
            'overviewPrevious' => $overviewPrevious,
            'deltas' => $deltas,
            'insights' => $insights,
            'performanceOverTime' => $performanceOverTime,
            'queries' => $queries,
            'pages' => $pages,
            'countries' => $countries,
            'devices' => $devices,
            'searchAppearance' => $searchAppearance,
            'sitemaps' => $sitemaps,
            'trackedKeywords' => $trackedKeywords,
            'googleConnections' => $googleConnections,
        ])->layout('components.layouts.app', [
            'siteContext' => $this->site,
            'title' => $this->site->name . ' — Search Console',
        ]);
    }

    private function computeDeltas(array $current, array $previous): array
    {
        $deltas = [];
        foreach (['clicks', 'impressions', 'ctr', 'position'] as $key) {
            $cur = $current[$key] ?? 0;
            $prev = $previous[$key] ?? 0;
            if ($prev != 0) {
                $deltas[$key] = round((($cur - $prev) / abs($prev)) * 100, 1);
            } else {
                $deltas[$key] = $cur > 0 ? 100 : null;
            }
        }
        return $deltas;
    }

    private function computeInsights(array $current, array $previous): array
    {
        $insights = [];
        $labels = [
            'clicks' => 'Clicks',
            'impressions' => 'Impressions',
            'ctr' => 'CTR',
            'position' => 'Average Position',
        ];
        // Position and CTR: lower position is better; higher CTR is better
        $invertedMetrics = ['position'];

        foreach (['clicks', 'impressions', 'ctr', 'position'] as $key) {
            $cur = $current[$key] ?? 0;
            $prev = $previous[$key] ?? 0;
            if ($prev == 0) continue;

            $change = round((($cur - $prev) / abs($prev)) * 100, 1);
            if (abs($change) < 25) continue;

            $isPositive = $change > 0;
            if (in_array($key, $invertedMetrics)) {
                $isPositive = !$isPositive; // Lower position = better
            }

            $insights[] = [
                'metric' => $labels[$key],
                'change' => $change,
                'direction' => $change > 0 ? 'up' : 'down',
                'type' => $isPositive ? 'good' : 'bad',
                'current' => $cur,
                'previous' => $prev,
            ];
        }

        return $insights;
    }
}
