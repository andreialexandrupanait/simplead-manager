<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\FetchAnalyticsData;
use App\Models\AnalyticsCache;
use App\Models\AnalyticsConnection;
use App\Models\GoogleConnection;
use App\Models\Site;
use App\Models\UpdateLog;
use App\Services\GoogleAnalyticsService;
use Carbon\Carbon;
use Livewire\Component;

class SiteAnalytics extends Component
{
    public Site $site;
    public string $dateRange = '28d';
    public ?string $customStart = null;
    public ?string $customEnd = null;
    public array $availableProperties = [];
    public ?array $realtimeData = null;

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

    public function setCustomDateRange(string $start, string $end): void
    {
        $this->dateRange = 'custom';
        $this->customStart = $start;
        $this->customEnd = $end;

        $connection = $this->site->analyticsConnection;
        if (!$connection) return;

        $cache = AnalyticsCache::where('site_id', $this->site->id)
            ->where('date_range', 'custom')
            ->where('start_date', $start)
            ->where('end_date', $end)
            ->latest('fetched_at')
            ->first();

        if (!$cache || $cache->expires_at->isPast()) {
            FetchAnalyticsData::dispatch($this->site, 'custom', $start, $end);
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

    public function fetchRealtimeData(): void
    {
        $connection = $this->site->analyticsConnection;
        if (!$connection || !$connection->is_active) return;

        $google = $connection->googleConnection;
        if (!$google || !$google->is_active) return;

        try {
            $service = new GoogleAnalyticsService($google);
            $this->realtimeData = $service->getRealtimeData($connection->property_id);
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Real-time data failed: ' . $e->getMessage());
        }
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
        $overviewPrevious = null;
        $usersOverTime = [];
        $trafficSources = [];
        $topPages = [];
        $devices = [];
        $countries = [];
        $cities = [];
        $referralSources = [];
        $landingPages = [];
        $demographics = [];
        $deltas = [];
        $insights = [];
        $annotations = [];

        if ($connection && $connection->is_active) {
            $cacheQuery = AnalyticsCache::where('site_id', $this->site->id)
                ->where('date_range', $this->dateRange);

            if ($this->dateRange === 'custom' && $this->customStart && $this->customEnd) {
                $cacheQuery->where('start_date', $this->customStart)->where('end_date', $this->customEnd);
            }

            $cache = $cacheQuery->latest('fetched_at')->first();

            if ($cache) {
                $data = $cache->data;
                $overview = $data['overview'] ?? null;
                $overviewPrevious = $data['overview_previous'] ?? null;
                $usersOverTime = $data['users_over_time'] ?? [];
                $trafficSources = $data['traffic_sources'] ?? [];
                $topPages = $data['top_pages'] ?? [];
                $devices = $data['devices'] ?? [];
                $countries = $data['countries'] ?? [];
                $cities = $data['cities'] ?? [];
                $referralSources = $data['referral_sources'] ?? [];
                $landingPages = $data['landing_pages'] ?? [];
                $demographics = $data['demographics'] ?? [];

                // Compute deltas and insights
                if ($overview && $overviewPrevious) {
                    $deltas = $this->computeDeltas($overview, $overviewPrevious);
                    $insights = $this->computeInsights($overview, $overviewPrevious);
                }

                // Build annotations from update logs
                if ($cache->start_date && $cache->end_date) {
                    $annotations = $this->getAnnotations($cache->start_date, $cache->end_date, $usersOverTime);
                }
            }
        }

        $googleConnections = GoogleConnection::where('is_active', true)->get();

        return view('livewire.sites.detail.site-analytics', [
            'connection' => $connection,
            'cache' => $cache,
            'overview' => $overview,
            'overviewPrevious' => $overviewPrevious,
            'deltas' => $deltas,
            'insights' => $insights,
            'annotations' => $annotations,
            'usersOverTime' => $usersOverTime,
            'trafficSources' => $trafficSources,
            'topPages' => $topPages,
            'devices' => $devices,
            'countries' => $countries,
            'cities' => $cities,
            'referralSources' => $referralSources,
            'landingPages' => $landingPages,
            'demographics' => $demographics,
            'googleConnections' => $googleConnections,
        ])->layout('components.layouts.app', [
            'siteContext' => $this->site,
            'title' => $this->site->name . ' — Analytics',
        ]);
    }

    private function computeDeltas(array $current, array $previous): array
    {
        $deltas = [];
        $metrics = ['total_users', 'new_users', 'sessions', 'pageviews', 'bounce_rate', 'avg_session_duration', 'engagement_rate'];
        foreach ($metrics as $key) {
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
            'total_users' => 'Users',
            'new_users' => 'New Users',
            'sessions' => 'Sessions',
            'pageviews' => 'Pageviews',
            'bounce_rate' => 'Bounce Rate',
            'engagement_rate' => 'Engagement Rate',
        ];
        // Lower bounce rate is better
        $invertedMetrics = ['bounce_rate'];

        foreach ($labels as $key => $label) {
            $cur = $current[$key] ?? 0;
            $prev = $previous[$key] ?? 0;
            if ($prev == 0) continue;

            $change = round((($cur - $prev) / abs($prev)) * 100, 1);
            if (abs($change) < 25) continue;

            $isPositive = $change > 0;
            if (in_array($key, $invertedMetrics)) {
                $isPositive = !$isPositive;
            }

            $insights[] = [
                'metric' => $label,
                'change' => $change,
                'direction' => $change > 0 ? 'up' : 'down',
                'type' => $isPositive ? 'good' : 'bad',
                'current' => $cur,
                'previous' => $prev,
            ];
        }

        return $insights;
    }

    private function getAnnotations($startDate, $endDate, array $usersOverTime): array
    {
        $logs = UpdateLog::where('site_id', $this->site->id)
            ->whereBetween('performed_at', [$startDate, $endDate])
            ->orderBy('performed_at')
            ->get();

        if ($logs->isEmpty()) return [];

        // Map dates from usersOverTime for label matching
        $dateLabels = collect($usersOverTime)->pluck('date')->map(fn($d) => Carbon::parse($d)->format('M d'))->toArray();

        $annotations = [];
        foreach ($logs as $log) {
            $dateLabel = $log->performed_at->format('M d');
            $label = ucfirst($log->type) . ': ' . $log->name . ' → ' . $log->to_version;
            $annotations[] = [
                'date' => $dateLabel,
                'label' => $label,
                'type' => $log->success ? 'success' : 'error',
            ];
        }

        return $annotations;
    }
}
