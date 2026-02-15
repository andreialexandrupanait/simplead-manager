<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\SyncWordPressSite;
use App\Models\AnalyticsCache;
use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Services\WordPressApiService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteOverview extends Component
{
    use WithJobTracking, WithSiteAuthorization;
    public Site $site;

    // Period selectors for cards
    public string $analyticsPeriod = '28d';

    // Connect plugin modal
    public string $apiKey = '';
    public string $apiSecret = '';
    public string $apiEndpoint = '';

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->initJobTracking();
    }

    protected function jobTrackingKeys(): array
    {
        return [
            'sync' => 'sync-wp-' . $this->site->id,
        ];
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        $this->site->refresh();
        unset($this->analyticsData, $this->updatesData, $this->performanceData, $this->searchConsoleData, $this->databaseData, $this->healthDimensions);
    }

    public function setAnalyticsPeriod(string $period): void
    {
        $this->analyticsPeriod = $period;
    }

    #[Computed]
    public function analyticsData(): ?array
    {
        $cache = AnalyticsCache::where('site_id', $this->site->id)
            ->where('date_range', $this->analyticsPeriod)
            ->latest('fetched_at')
            ->first();

        return $cache?->data;
    }

    #[Computed]
    public function updatesData(): array
    {
        $core = $this->site->core_update_version ? 1 : 0;
        $plugins = $this->site->sitePlugins()->where('has_update', true)->count();
        $themes = $this->site->siteThemes()->where('has_update', true)->count();

        return [
            'core' => $core,
            'plugins' => $plugins,
            'themes' => $themes,
            'total' => $core + $plugins + $themes,
        ];
    }

    #[Computed]
    public function activeReportSchedules(): Collection
    {
        return $this->site->reportSchedules()->where('is_active', true)->get();
    }

    #[Computed]
    public function lastReport(): ?object
    {
        return $this->site->reports()->latest('created_at')->first();
    }

    #[Computed]
    public function backupStorageUsed(): int
    {
        return (int) $this->site->backups()->where('status', 'completed')->sum('file_size');
    }

    #[Computed]
    public function performanceData(): ?array
    {
        $monitor = $this->site->performanceMonitor;
        if (!$monitor) return null;

        $metricKeys = ['fcp', 'si', 'lcp', 'tti', 'tbt', 'cls'];

        $buildMetrics = function ($test) use ($metricKeys) {
            if (!$test) return null;
            $metrics = [];
            foreach ($metricKeys as $key) {
                $metrics[$key] = [
                    'value' => $test->formatMetric($key),
                    'color' => $test->metricColor($key),
                ];
            }
            return $metrics;
        };

        return [
            'mobile_score' => $monitor->latest_mobile_score,
            'desktop_score' => $monitor->latest_desktop_score,
            'last_tested_at' => $monitor->last_tested_at,
            'is_active' => $monitor->is_active,
            'mobile_metrics' => $buildMetrics($monitor->latestMobileTest),
            'desktop_metrics' => $buildMetrics($monitor->latestDesktopTest),
        ];
    }

    #[Computed]
    public function searchConsoleData(): ?array
    {
        $cache = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->where('data_type', 'overview')
            ->latest('fetched_at')
            ->first();

        return $cache?->data;
    }

    #[Computed]
    public function databaseData(): ?array
    {
        $config = $this->site->databaseCleanupConfig;
        $base = $config ? [
            'is_enabled' => $config->is_enabled,
            'last_cleanup_at' => $config->last_cleanup_at,
            'next_cleanup_at' => $config->next_cleanup_at,
        ] : null;

        // Add optimization stats from cache (populated during sync)
        $stats = Cache::get("db-cleanup-stats-{$this->site->id}");
        if ($stats) {
            $categories = [
                'Posts' => ($stats['revisions'] ?? 0) + ($stats['auto_drafts'] ?? 0) + ($stats['trashed_posts'] ?? 0),
                'Comments' => ($stats['spam_comments'] ?? 0) + ($stats['trashed_comments'] ?? 0),
                'Metadata' => ($stats['orphaned_postmeta'] ?? 0) + ($stats['orphaned_commentmeta'] ?? 0) + ($stats['orphaned_usermeta'] ?? 0) + ($stats['orphaned_termmeta'] ?? 0),
                'Transients' => $stats['expired_transients'] ?? 0,
            ];
            $total = array_sum($categories);

            $base = $base ?? [];
            $base['optimization_categories'] = $categories;
            $base['optimization_total'] = $total;
        }

        return $base;
    }

    #[Computed]
    public function healthDimensions(): array
    {
        return \App\Helpers\SiteStatusHelper::compute($this->site);
    }

    public function runBackup(): void
    {
        // Dispatch backup job
        dispatch(new \App\Jobs\CreateBackup($this->site, 'full', 'manual'));

        $this->dispatch('notify', type: 'success', message: 'Backup started');
    }

    public function syncNow(): void
    {
        $this->dispatchTrackedJob('sync', new SyncWordPressSite($this->site), 'Syncing site data...');
    }

    public function clearCache(): void
    {
        try {
            $api = new WordPressApiService($this->site);
            $result = $api->clearCache();
            $cleared = $result['cleared'] ?? [];
            $this->dispatch('notify', type: 'success', message: 'Cache cleared (' . count($cleared) . ' layer' . (count($cleared) !== 1 ? 's' : '') . ')');
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Failed to clear cache: ' . $e->getMessage());
        }
    }

    public function openWpAdmin(): void
    {
        try {
            $api = new WordPressApiService($this->site);
            $result = $api->getLoginUrl();

            if (!empty($result['login_url'])) {
                $this->js("window.open('" . addslashes($result['login_url']) . "', '_blank')");
                return;
            }

            session()->flash('wp-admin-error', 'Could not generate login URL. No URL returned.');
        } catch (\Exception $e) {
            session()->flash('wp-admin-error', 'Could not generate login URL: ' . $e->getMessage());
        }
    }

    public function openConnectModal(): void
    {
        $this->apiKey = $this->site->api_key ?? '';
        $this->apiSecret = $this->site->api_secret ?? '';
        $this->apiEndpoint = $this->site->api_endpoint ?? '';

        // Auto-fill endpoint from site URL if empty
        if (empty($this->apiEndpoint) && $this->site->url) {
            $this->apiEndpoint = rtrim($this->site->url, '/') . '/wp-json/jesuspended/v1';
        }

        $this->dispatch('open-modal-connect-plugin');
    }

    public function saveCredentials(): void
    {
        $this->validate([
            'apiKey' => 'required|min:10',
            'apiSecret' => 'required|min:10',
            'apiEndpoint' => 'required|url',
        ]);

        $this->site->update([
            'api_key' => $this->apiKey,
            'api_secret' => $this->apiSecret,
            'api_endpoint' => $this->apiEndpoint,
        ]);

        SyncWordPressSite::dispatch($this->site);

        $this->dispatch('close-modal-connect-plugin');
        session()->flash('success', 'Credentials saved. Syncing site...');
    }

    public function disconnectSite(): void
    {
        $this->site->update([
            'api_key' => null,
            'api_secret' => null,
            'api_endpoint' => null,
            'is_connected' => false,
        ]);

        $this->apiKey = '';
        $this->apiSecret = '';
        $this->apiEndpoint = '';

        $this->dispatch('close-modal-connect-plugin');
        session()->flash('success', 'Site disconnected.');
    }

    public function resumeMonitoring(): void
    {
        $health = $this->site->healthState;
        if ($health) {
            $health->update([
                'circuit_state' => 'half_open',
                'consecutive_failures' => 0,
            ]);
            $this->site->refresh();
            $this->dispatch('notify', type: 'success', message: 'Circuit breaker reset. A test check will run shortly.');
        }
    }

    public function enableMonitoring(): void
    {
        $health = $this->site->healthState;
        if ($health) {
            $health->update([
                'is_monitoring_disabled' => false,
                'circuit_state' => 'closed',
                'consecutive_failures' => 0,
            ]);
            $this->site->refresh();
            $this->dispatch('notify', type: 'success', message: 'Monitoring re-enabled.');
        }
    }

    public function openAssignClientModal(): void
    {
        // Open assign client modal
        $this->dispatch('open-assign-client-modal');
    }

    public function render()
    {
        return view('livewire.sites.detail.site-overview')->layout('components.layouts.app', [
            'siteContext' => $this->site,
            'title' => $this->site->name . ' — Overview',
            'maxWidth' => '',
        ]);
    }
}
