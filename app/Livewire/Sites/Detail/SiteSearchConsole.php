<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Jobs\FetchSearchConsoleData;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\GoogleConnection;
use App\Models\SearchConsoleCache;
use App\Models\SearchConsoleConnection;
use App\Models\Site;
use App\Services\GoogleSearchConsoleService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteSearchConsole extends Component
{
    use WithJobTracking, WithSiteAuthorization;

    public Site $site;

    public string $dateRange = '28d';

    public ?string $customStart = null;

    public ?string $customEnd = null;

    public array $availableProperties = [];

    protected function jobTrackingKeys(): array
    {
        return ['fetch' => 'search-console-'.$this->site->id];
    }

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->initJobTracking();

        // Auto-trigger property picker after OAuth return
        if (session('success') && ! $this->site->searchConsoleConnection) {
            $this->connectSearchConsole();
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
        $conn = $this->site->searchConsoleConnection;
        if (! $conn) {
            return null;
        }

        $google = $conn->googleConnection;

        return [
            'email' => $google->email,
            'property' => $conn->property_url,
            'google_active' => $google->is_active ?? false,
            'last_error' => $conn->last_error,
            'last_sync' => $conn->last_sync_at,
        ];
    }

    public function reconnectGoogle(): void
    {
        $this->redirect(route('google.auth', ['return_url' => route('sites.search-console', $this->site)]));
    }

    public function changeProperty(): void
    {
        $this->connectSearchConsole();
    }

    public function setDateRange(string $range): void
    {
        $this->dateRange = $range;

        $connection = $this->site->searchConsoleConnection;
        if (! $connection) {
            return;
        }

        $cache = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', $this->dateRange)
            ->where('data_type', 'overview')
            ->latest('fetched_at')
            ->first();

        if (! $cache || $cache->expires_at->isPast()) {
            $this->dispatchTrackedJob('fetch', new FetchSearchConsoleData($this->site, $this->dateRange), 'Fetching Search Console data...');
        }
    }

    public function setCustomDateRange(string $start, string $end): void
    {
        $this->dateRange = 'custom';
        $this->customStart = $start;
        $this->customEnd = $end;

        $connection = $this->site->searchConsoleConnection;
        if (! $connection) {
            return;
        }

        $cache = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', 'custom')
            ->where('data_type', 'overview')
            ->where('start_date', $start)
            ->where('end_date', $end)
            ->latest('fetched_at')
            ->first();

        if (! $cache || $cache->expires_at->isPast()) {
            $this->dispatchTrackedJob('fetch', new FetchSearchConsoleData($this->site, 'custom', $start, $end), 'Fetching Search Console data...');
        }
    }

    public function refreshData(): void
    {
        $connection = $this->site->searchConsoleConnection;
        if (! $connection || ! $connection->is_active) {
            return;
        }

        $this->dispatchTrackedJob('fetch', new FetchSearchConsoleData($this->site, $this->dateRange), 'Fetching Search Console data...');
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        // Data will refresh automatically on next render since it reads from cache
    }

    public function connectSearchConsole(): void
    {
        $googleConnection = $this->resolveGoogleConnection();

        if (! $googleConnection) {
            $this->redirect(route('google.auth', ['return_url' => route('sites.search-console', $this->site)]));

            return;
        }

        try {
            $service = new GoogleSearchConsoleService($googleConnection);
            $this->availableProperties = $service->listProperties();

            if (empty($this->availableProperties)) {
                session()->flash('error', 'No Search Console properties found for this Google account. Make sure the account has access to a Search Console property.');
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
        $this->site->searchConsoleConnection->delete();
        SearchConsoleCache::where('site_id', $this->site->id)->delete();
        $this->site->unsetRelation('searchConsoleConnection');

        session()->flash('success', 'Search Console disconnected.');
    }

    private function resolveGoogleConnection(): ?GoogleConnection
    {
        $existing = $this->site->searchConsoleConnection->googleConnection;
        if ($existing && $existing->is_active) {
            return $existing;
        }

        return GoogleConnection::where('is_active', true)->first();
    }

    public function render()
    {
        $connection = $this->site->searchConsoleConnection;
        $overview = null;
        $performanceOverTime = [];
        $queries = [];
        $cache = null;

        if ($connection && $connection->is_active) {
            $query = SearchConsoleCache::where('site_id', $this->site->id)
                ->where('date_range', $this->dateRange);

            if ($this->dateRange === 'custom' && $this->customStart && $this->customEnd) {
                $query->where('start_date', $this->customStart)->where('end_date', $this->customEnd);
            }

            $caches = $query->get()->keyBy('data_type');

            $cache = $caches->get('overview');
            $overview = $cache->data;
            $performanceOverTime = $caches->get('performance_over_time')->data ?? [];
            $queries = $caches->get('queries')->data ?? [];
        }

        $googleConnections = GoogleConnection::where('is_active', true)->get();

        return view('livewire.sites.detail.site-search-console', [
            'connection' => $connection,
            'cache' => $cache,
            'overview' => $overview,
            'performanceOverTime' => $performanceOverTime,
            'queries' => $queries,
            'googleConnections' => $googleConnections,
        ])->layout('components.layouts.app', [
            'siteContext' => $this->site,
            'title' => $this->site->name.' — Search Console',
        ]);
    }
}
