<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Jobs\SyncWordPressSite;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Livewire\Traits\WithWpAdminLogin;
use App\Models\ActivityLog;
use App\Models\AnalyticsCache;
use App\Models\HealthScoreHistory;
use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteOverview extends Component
{
    use WithJobTracking, WithSiteAuthorization, WithWpAdminLogin;

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
        $site->loadMissing([
            'uptimeMonitor',
            'securityMonitor',
            'backupConfig.storageDestination',
            'latestCompletedBackup',
            'client',
            'performanceMonitor.latestMobileTest',
            'performanceMonitor.latestDesktopTest',
            'databaseCleanupConfig',
            'healthState',
            'siteCloudflare',
            'searchConsoleConnection',
            'analyticsConnection',
            'wpAdminUser',
        ]);
        $this->site = $site;
        $this->initJobTracking();

        // Load cached server resources
        $cached = Cache::get("server-resources-{$this->site->id}");
        if ($cached) {
            $this->serverResources = $cached['data'];
            $this->serverResourcesLoadedAt = $cached['loaded_at'];
        }
    }

    protected function jobTrackingKeys(): array
    {
        return [
            'sync' => 'sync-wp-'.$this->site->id,
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
        if (! $monitor) {
            return null;
        }

        $metricKeys = ['fcp', 'si', 'lcp', 'tti', 'tbt', 'cls'];

        $buildMetrics = function ($test) use ($metricKeys) {
            if (! $test) {
                return null;
            }
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

    #[Computed]
    public function healthBreakdown(): array
    {
        return \App\Services\HealthScoreService::calculate($this->site);
    }

    #[Computed]
    public function recentActivity(): \Illuminate\Support\Collection
    {
        return ActivityLog::where('site_id', $this->site->id)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('created_at')
            ->limit(10)
            ->get();
    }

    #[Computed]
    public function healthTrend(): array
    {
        $history = HealthScoreHistory::where('site_id', $this->site->id)
            ->where('recorded_at', '>=', now()->subDays(30))
            ->orderBy('recorded_at')
            ->pluck('score')
            ->toArray();
        $current = end($history) ?: 0;
        $oldest = reset($history) ?: $current;
        $change = $current - $oldest;

        return ['history' => $history, 'change' => $change, 'direction' => $change > 0 ? 'up' : ($change < 0 ? 'down' : 'neutral')];
    }

    #[Computed]
    public function dnsStatus(): array
    {
        $monitor = $this->site->dnsMonitor;
        if (! $monitor || ! $monitor->current_records) {
            return ['available' => false];
        }
        $records = $monitor->current_records;
        $txt = implode(' ', $records['TXT'] ?? []);

        return [
            'available' => true,
            'has_spf' => stripos($txt, 'v=spf1') !== false,
            'has_dmarc' => ! empty($records['DMARC'] ?? []),
            'has_dkim' => ! empty($records['DKIM'] ?? []),
            'has_changes' => $monitor->has_changes,
            'last_checked' => $monitor->last_checked_at,
        ];
    }

    #[Computed]
    public function errorLogStatus(): array
    {
        $counts = \App\Models\PhpErrorLog::where('site_id', $this->site->id)
            ->where('is_resolved', false)
            ->selectRaw("COUNT(*) as total, SUM(CASE WHEN level = 'fatal' THEN 1 ELSE 0 END) as fatal")
            ->first();

        return ['fatal' => (int) $counts->fatal, 'total' => (int) $counts->total];
    }

    public function runBackup(): void
    {
        $this->authorizeSiteModification($this->site);
        $rateLimitKey = "backup:{$this->site->id}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, 3600)) {
            $this->dispatch('notify', type: 'error', message: 'Too many backup requests. Please wait before trying again.');

            return;
        }

        dispatch(new \App\Jobs\CreateBackup($this->site, 'full', 'manual'));
        $this->dispatch('notify', type: 'success', message: 'Backup started');
    }

    public function syncNow(): void
    {
        $this->authorizeSiteModification($this->site);
        $rateLimitKey = "sync:{$this->site->id}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 10, fn () => true, 3600)) {
            $this->dispatch('notify', type: 'error', message: 'Too many sync requests. Please wait before trying again.');

            return;
        }

        $this->dispatchTrackedJob('sync', new SyncWordPressSite($this->site), 'Syncing site data...');
    }

    public ?array $serverResources = null;

    public ?string $serverResourcesLoadedAt = null;

    #[Computed]
    public function serverResourcesIsStale(): bool
    {
        if (! $this->serverResourcesLoadedAt) {
            return true;
        }

        return \Carbon\Carbon::parse($this->serverResourcesLoadedAt)->diffInMinutes(now()) >= 5;
    }

    public function loadServerResources(): void
    {
        try {
            $api = app(WordPressApiServiceFactory::class)->make($this->site);
            $this->serverResources = $api->getServerResources();
            $this->serverResourcesLoadedAt = now()->toIso8601String();

            Cache::put("server-resources-{$this->site->id}", [
                'data' => $this->serverResources,
                'loaded_at' => $this->serverResourcesLoadedAt,
            ], 600); // 10 minutes
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Failed to load server resources: '.$e->getMessage());
        }
    }

    public function clearCache(): void
    {
        $this->authorizeSiteModification($this->site);
        try {
            $api = app(WordPressApiServiceFactory::class)->make($this->site);
            $result = $api->clearCache();
            $cleared = $result['cleared'] ?? [];
            $this->dispatch('notify', type: 'success', message: 'Cache cleared ('.count($cleared).' layer'.(count($cleared) !== 1 ? 's' : '').')');
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Failed to clear cache: '.$e->getMessage());
        }
    }

    public function openConnectModal(): void
    {
        $this->authorizeSiteModification($this->site);

        $this->apiKey = $this->site->api_key ?? '';
        // Never round-trip the decrypted secret to the browser; the admin
        // re-pastes it to save. Existing credentials keep working untouched.
        $this->apiSecret = '';
        $this->apiEndpoint = $this->site->api_endpoint ?? '';

        // Auto-fill endpoint from site URL if empty
        if (empty($this->apiEndpoint) && $this->site->url) {
            $this->apiEndpoint = rtrim($this->site->url, '/').'/wp-json/simplead/v1';
        }

        $this->dispatch('open-modal-connect-plugin');
    }

    public function saveCredentials(): void
    {
        $this->authorizeSiteModification($this->site);
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
        $this->authorizeSiteModification($this->site);
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

    public function rotateApiKeys(): void
    {
        $this->authorizeSiteModification($this->site);
        if (! $this->site->is_connected) {
            $this->dispatch('notify', type: 'error', message: 'Site is not connected.');

            return;
        }

        try {
            $api = $this->apiFactory->make($this->site);
            $result = $api->rotateApiKeys();

            $this->site->update([
                'api_key' => $result['new_key'],
                'api_secret' => $result['new_secret'],
            ]);

            $this->apiKey = $result['new_key'];
            $this->apiSecret = $result['new_secret'];

            $this->dispatch('notify', type: 'success', message: 'API keys rotated successfully.');
        } catch (\Throwable $e) {
            $this->dispatch('notify', type: 'error', message: 'Key rotation failed: '.$e->getMessage());
        }
    }

    public function resumeMonitoring(): void
    {
        $this->authorizeSiteModification($this->site);
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
        $this->authorizeSiteModification($this->site);
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
            'title' => $this->site->name.' — Overview',
            'maxWidth' => '',
        ]);
    }
}
