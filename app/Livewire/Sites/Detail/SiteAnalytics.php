<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Jobs\FetchAnalyticsData;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\AnalyticsCache;
use App\Models\AnalyticsConnection;
use App\Models\GoogleConnection;
use App\Models\Site;
use App\Models\UpdateLog;
use App\Services\GoogleAnalyticsService;
use Carbon\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteAnalytics extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public string $dateRange = '28d';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    public array $availableProperties = [];

    public ?array $realtimeData = null;

    public ?int $selectedGoogleConnectionId = null;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;

        // Auto-trigger property picker after OAuth return
        if (session('success') && ! $this->site->analyticsConnection) {
            $this->connectAnalytics();
        }
    }

    #[Computed]
    public function hasGoogleCredentials(): bool
    {
        return ! empty(config('services.google.client_id'));
    }

    #[Computed]
    public function hasGoogleAccounts(): bool
    {
        return GoogleConnection::where('is_active', true)->exists();
    }

    #[Computed]
    public function googleConnectionStatus(): ?array
    {
        $conn = $this->site->analyticsConnection;
        if (! $conn) {
            return null;
        }

        $google = $conn->googleConnection;

        return [
            'email' => $google?->email,
            'property' => $conn->property_name ?? $conn->property_id,
            'google_active' => $google?->is_active ?? false,
            'last_error' => $conn->last_error,
            'last_sync' => $conn->last_sync_at,
        ];
    }

    public function reconnectGoogle(): void
    {
        $this->redirect(route('google.auth', ['return_url' => route('sites.analytics', $this->site)]));
    }

    public function changeProperty(): void
    {
        $this->connectAnalytics();
    }

    public function switchGoogleAccount(int $connectionId): void
    {
        $this->selectedGoogleConnectionId = $connectionId;
        $this->connectAnalytics();
    }

    public function setDateRange(string $range): void
    {
        $this->dateRange = $range;

        $connection = $this->site->analyticsConnection;
        if (! $connection) {
            return;
        }

        $cache = AnalyticsCache::where('site_id', $this->site->id)
            ->where('date_range', $this->dateRange)
            ->latest('fetched_at')
            ->first();

        if (! $cache || $cache->expires_at->isPast()) {
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
        if (! $connection) {
            return;
        }

        $cache = AnalyticsCache::where('site_id', $this->site->id)
            ->where('date_range', 'custom')
            ->where('start_date', $start)
            ->where('end_date', $end)
            ->latest('fetched_at')
            ->first();

        if (! $cache || $cache->expires_at->isPast()) {
            FetchAnalyticsData::dispatch($this->site, 'custom', $start, $end);
            session()->flash('analytics-refreshing', true);
        }
    }

    public function refreshData(): void
    {
        $connection = $this->site->analyticsConnection;
        if (! $connection || ! $connection->is_active) {
            return;
        }

        FetchAnalyticsData::dispatch($this->site, $this->dateRange);
        session()->flash('analytics-refreshing', true);
    }

    public function connectAnalytics(): void
    {
        $googleConnection = $this->resolveGoogleConnection();

        if (! $googleConnection) {
            $this->redirect(route('google.auth', ['return_url' => route('sites.analytics', $this->site)]));

            return;
        }

        try {
            $service = new GoogleAnalyticsService($googleConnection);
            $this->availableProperties = $service->listProperties();

            if (empty($this->availableProperties)) {
                session()->flash('error', 'No GA4 properties found for this Google account. Make sure the account has access to a GA4 property.');
            }
        } catch (\Exception $e) {
            session()->flash('error', 'Failed to list properties: '.$e->getMessage());
        }
    }

    public function selectProperty(int $index): void
    {
        $property = $this->availableProperties[$index] ?? null;
        if (! $property) {
            return;
        }

        $googleConnection = $this->resolveGoogleConnection();
        if (! $googleConnection) {
            return;
        }

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
        if (! $connection || ! $connection->is_active) {
            return;
        }

        $google = $connection->googleConnection;
        if (! $google || ! $google->is_active) {
            return;
        }

        try {
            $service = new GoogleAnalyticsService($google);
            $this->realtimeData = $service->getRealtimeData($connection->property_id);
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Real-time data failed: '.$e->getMessage());
        }
    }

    public function disconnectAnalytics(): void
    {
        $this->site->analyticsConnection?->delete();
        AnalyticsCache::where('site_id', $this->site->id)->delete();
        $this->site->unsetRelation('analyticsConnection');

        session()->flash('success', 'Analytics disconnected.');
    }

    private function resolveGoogleConnection(): ?GoogleConnection
    {
        // Use explicitly selected account if set
        if ($this->selectedGoogleConnectionId) {
            $selected = GoogleConnection::where('id', $this->selectedGoogleConnectionId)
                ->where('is_active', true)
                ->first();
            if ($selected) {
                return $selected;
            }
        }

        // Use the site's existing connection's Google account if available
        $existing = $this->site->analyticsConnection?->googleConnection;
        if ($existing && $existing->is_active) {
            return $existing;
        }

        return GoogleConnection::where('is_active', true)->first();
    }

    public function render()
    {
        $connection = $this->site->analyticsConnection;
        $cache = null;
        $overview = null;
        $usersOverTime = [];
        $trafficSources = [];
        $topPages = [];
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
                $usersOverTime = $data['users_over_time'] ?? [];
                $trafficSources = $data['traffic_sources'] ?? [];
                $topPages = $data['top_pages'] ?? [];

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
            'annotations' => $annotations,
            'usersOverTime' => $usersOverTime,
            'trafficSources' => $trafficSources,
            'topPages' => $topPages,
            'googleConnections' => $googleConnections,
        ])->layout('components.layouts.app', [
            'siteContext' => $this->site,
            'title' => $this->site->name.' — Analytics',
        ]);
    }

    private function getAnnotations($startDate, $endDate, array $usersOverTime): array
    {
        $logs = UpdateLog::where('site_id', $this->site->id)
            ->whereBetween('performed_at', [$startDate, $endDate])
            ->orderBy('performed_at')
            ->get();

        if ($logs->isEmpty()) {
            return [];
        }

        // Map dates from usersOverTime for label matching
        $dateLabels = collect($usersOverTime)->pluck('date')->map(fn ($d) => Carbon::parse($d)->format('M d'))->toArray();

        $annotations = [];
        foreach ($logs as $log) {
            $dateLabel = $log->performed_at->format('M d');
            $label = ucfirst($log->type).': '.$log->name.' → '.$log->to_version;
            $annotations[] = [
                'date' => $dateLabel,
                'label' => $label,
                'type' => $log->success ? 'success' : 'error',
            ];
        }

        return $annotations;
    }
}
