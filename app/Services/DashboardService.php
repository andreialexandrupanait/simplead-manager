<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BackupStatus;
use App\Enums\HealthLevel;
use App\Enums\MonitorState;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\PhpErrorLog;
use App\Models\Site;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use App\Models\VulnerabilityAlert;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;

class DashboardService
{
    private const CACHE_TTL = 60; // seconds

    private const CACHE_TAG = 'dashboard';

    /**
     * Site IDs the acting user is allowed to see, or null for admins /
     * out-of-request contexts (no restriction). Non-admins are limited to the
     * sites they own or reach through an assigned client — the same rule as
     * User::canAccessSite().
     *
     * @return array<int>|null
     */
    private function accessibleSiteIds(): ?array
    {
        $user = auth()->user();

        if (! $user || $user->isAdmin()) {
            return null;
        }

        $clientIds = $user->assignedClients()->pluck('clients.id');

        return Site::query()
            ->where('user_id', $user->id)
            ->when($clientIds->isNotEmpty(), fn ($q) => $q->orWhereIn('client_id', $clientIds))
            ->pluck('id')
            ->all();
    }

    /**
     * Per-scope cache key so one user's scoped aggregates never leak to another.
     */
    private function scopedKey(string $key): string
    {
        $user = auth()->user();
        $scope = ($user && ! $user->isAdmin()) ? 'u'.$user->id : 'all';

        return $key.':'.$scope;
    }

    /**
     * @return \Illuminate\Cache\TaggedCache
     */
    private function cache()
    {
        return Cache::tags(self::CACHE_TAG);
    }

    /**
     * Constrain a Site query to the accessible IDs (no-op for admins).
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<int>|null  $ids
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    private function scopeSite($query, ?array $ids)
    {
        return $query->when($ids !== null, fn ($q) => $q->whereIn('id', $ids));
    }

    /**
     * Constrain a site-owned model query (has a site_id) to the accessible
     * sites. For admins, fall back to the original orphan-excluding whereHas.
     *
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  \Illuminate\Database\Eloquent\Builder<TModel>  $query
     * @param  array<int>|null  $ids
     * @return \Illuminate\Database\Eloquent\Builder<TModel>
     */
    private function scopeRelated($query, ?array $ids)
    {
        return $query->when(
            $ids !== null,
            fn ($q) => $q->whereIn('site_id', $ids),
            fn ($q) => $q->whereHas('site'),
        );
    }

    public function getStats(): array
    {
        return $this->cache()->remember($this->scopedKey('dashboard:stats'), self::CACHE_TTL, fn () => $this->computeStats());
    }

    private function computeStats(): array
    {
        $ids = $this->accessibleSiteIds();

        // Sites the connector can no longer reach — their backups, scans,
        // performance tests and sync are all paused until they reconnect.
        // Scoped like everything else so a Viewer/Manager only counts their own.
        $disconnectedSites = $this->scopeSite(Site::where('is_connected', false), $ids)->count();

        $totalSites = $this->scopeSite(Site::query(), $ids)->count();
        $sitesDown = $this->scopeSite(Site::where('is_up', false), $ids)->count();

        $avgUptime = $this->scopeRelated(UptimeMonitor::query(), $ids)->whereNotNull('uptime_30d')->avg('uptime_30d');
        $avgResponseTime = $this->scopeRelated(UptimeMonitor::query(), $ids)
            ->whereNotNull('avg_response_time')
            ->where('avg_response_time', '>', 0)
            ->avg('avg_response_time');

        $pendingPluginUpdates = $this->scopeRelated(\App\Models\SitePlugin::query(), $ids)->where('has_update', true)->count();
        $pendingThemeUpdates = $this->scopeRelated(\App\Models\SiteTheme::query(), $ids)->where('has_update', true)->count();
        $pendingCoreUpdates = $this->scopeSite(Site::whereNotNull('core_update_version'), $ids)->count();

        $backups = $this->getBackupCounts($ids);

        // Sites with backup enabled where last successful backup is older than 36h (or never)
        $staleBackups = $this->scopeSite(Site::whereHas('backupConfig', fn ($q) => $q->where('is_enabled', true)), $ids)
            ->where(fn ($q) => $q
                ->whereNull('last_backup_at')
                ->orWhere('last_backup_at', '<', now()->subHours(36))
            )
            ->count();

        // Backups completed in the last 30 days that have failed integrity verification
        // OR have not been verified in the last 14 days. Restore reliability signal.
        $unverifiedBackups = $this->scopeRelated(\App\Models\Backup::query(), $ids)
            ->where('status', 'completed')
            ->where('created_at', '>=', now()->subDays(30))
            ->where(fn ($q) => $q
                ->where('verification_status', 'failed')
                ->orWhere('verification_status', 'never_tested')
                ->orWhere('verified_at', '<', now()->subDays(14))
            )
            ->count();

        $backupHealth = app(\App\Services\Backup\BackupHealthService::class)->aggregate(5, $ids);

        return [
            'total_sites' => $totalSites,
            'sites_down' => $sitesDown,
            'disconnected_sites' => $disconnectedSites,
            'avg_uptime' => $avgUptime ? round((float) $avgUptime, 2) : null,
            'avg_response_time' => $avgResponseTime ? (int) round((float) $avgResponseTime) : null,
            'pending_updates' => $pendingPluginUpdates + $pendingThemeUpdates + $pendingCoreUpdates,
            'pending_plugin_updates' => $pendingPluginUpdates,
            'pending_theme_updates' => $pendingThemeUpdates,
            'pending_core_updates' => $pendingCoreUpdates,
            'failed_backups' => $backups['failed_backups'],
            'stale_backups' => $staleBackups,
            'unverified_backups' => $unverifiedBackups,
            'backup_health' => $backupHealth,
            'total_alerts' => $sitesDown + $disconnectedSites + $backups['failed_backups'] + $staleBackups,
            'backup_storage_bytes' => $backups['total_storage_bytes'],
            'backups_today' => $backups['backups_today'],
        ];
    }

    public function getTrends(): array
    {
        return $this->cache()->remember($this->scopedKey('dashboard:trends'), self::CACHE_TTL, fn () => $this->computeTrends());
    }

    private function computeTrends(): array
    {
        $ids = $this->accessibleSiteIds();

        // Uptime trend: compare 7-day average against 30-day average.
        // If the recent 7d average is higher than 30d it is improving; lower means degrading.
        $avg7d = $this->scopeRelated(UptimeMonitor::query(), $ids)
            ->whereNotNull('uptime_7d')
            ->avg('uptime_7d');

        $avg30d = $this->scopeRelated(UptimeMonitor::query(), $ids)
            ->whereNotNull('uptime_30d')
            ->avg('uptime_30d');

        if ($avg7d !== null && $avg30d !== null) {
            $diff7v30 = (float) $avg7d - (float) $avg30d;
            $uptimeTrend = $diff7v30 > 0.1 ? 'up' : ($diff7v30 < -0.1 ? 'down' : 'neutral');
            $uptimeDelta = round($diff7v30, 2);
        } else {
            $uptimeTrend = 'neutral';
            $uptimeDelta = null;
        }

        // Pending updates trend: persist a rolling snapshot in cache (refreshed hourly).
        $currentUpdates = $this->scopeRelated(\App\Models\SitePlugin::query(), $ids)->where('has_update', true)->count()
            + $this->scopeRelated(\App\Models\SiteTheme::query(), $ids)->where('has_update', true)->count()
            + $this->scopeSite(Site::whereNotNull('core_update_version'), $ids)->count();

        $prevUpdatesKey = $this->scopedKey('dashboard:trends:prev_updates');
        $previousUpdates = Cache::get($prevUpdatesKey);
        if ($previousUpdates === null) {
            Cache::put($prevUpdatesKey, $currentUpdates, 3600);
            $updatesTrend = 'neutral';
            $updatesDelta = null;
        } else {
            $updatesDelta = $currentUpdates - (int) $previousUpdates;
            $updatesTrend = $updatesDelta > 0 ? 'up' : ($updatesDelta < 0 ? 'down' : 'neutral');
            Cache::put($prevUpdatesKey, $currentUpdates, 3600);
        }

        // Failed backups trend: compare last 24 h against the prior 24–48 h window.
        $failedLast24h = $this->scopeRelated(Backup::query(), $ids)
            ->where('status', BackupStatus::Failed)
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        $failedPrev24h = $this->scopeRelated(Backup::query(), $ids)
            ->where('status', BackupStatus::Failed)
            ->whereBetween('created_at', [now()->subHours(48), now()->subHours(24)])
            ->count();

        $backupsDelta = $failedLast24h - $failedPrev24h;
        $backupsTrend = $backupsDelta > 0 ? 'up' : ($backupsDelta < 0 ? 'down' : 'neutral');

        return [
            'uptime' => [
                'direction' => $uptimeTrend,
                'delta' => $uptimeDelta,
            ],
            'pending_updates' => [
                'direction' => $updatesTrend,
                'delta' => $updatesDelta ?? null,
            ],
            'failed_backups' => [
                'direction' => $backupsTrend,
                'delta' => $backupsDelta,
            ],
        ];
    }

    public static function invalidateCache(): void
    {
        // Aggregates are cached per user scope (dashboard:stats:u7, …:all, etc.);
        // flushing the tag clears every scope in one call.
        Cache::tags(self::CACHE_TAG)->flush();
    }

    public function getAlerts(): array
    {
        return $this->cache()->remember($this->scopedKey('dashboard:alerts'), self::CACHE_TTL, fn () => $this->computeAlerts());
    }

    private function computeAlerts(): array
    {
        $ids = $this->accessibleSiteIds();
        $alerts = [];

        // Sites down — one alert per site
        $sitesDown = $this->scopeSite(Site::where('is_up', false), $ids)->with('uptimeMonitor')->get();
        foreach ($sitesDown as $site) {
            $alerts[] = [
                'key' => "sites_down_{$site->id}",
                'severity' => 'critical',
                'icon' => 'activity',
                'title' => "{$site->name} is down",
                'description' => null,
                'url' => route('sites.uptime', $site),
                'timestamp' => $site->uptimeMonitor?->last_checked_at,
            ];
        }

        // Backup failures (last 24h) — only if no successful backup happened after
        $failedBackups = $this->scopeRelated(Backup::query(), $ids)
            ->where('status', BackupStatus::Failed)
            ->where('created_at', '>=', now()->subDay())
            ->with('site')
            ->get();

        $failedBysite = $failedBackups->groupBy('site_id');
        foreach ($failedBysite as $siteId => $backups) {
            $lastFailedAt = $backups->max('created_at');
            $hasSuccessAfter = Backup::where('site_id', $siteId)
                ->where('status', BackupStatus::Completed)
                ->where('created_at', '>', $lastFailedAt)
                ->exists();

            if ($hasSuccessAfter) {
                continue;
            }

            $site = $backups->first()->site;
            $alerts[] = [
                'key' => "backup_failed_{$site->id}",
                'severity' => 'critical',
                'icon' => 'hard-drive',
                'title' => "Backup failed for {$site->name}",
                'description' => null,
                'url' => route('sites.backups', $site),
                'timestamp' => $lastFailedAt,
                'action' => "retry_backup_{$site->id}",
            ];
        }

        // Sort by timestamp desc (all current alert types have the same severity)
        usort($alerts, fn ($a, $b) => ($b['timestamp'] ?? now()) <=> ($a['timestamp'] ?? now()));

        return $alerts;
    }

    public function getSitesOverview(int $perPage = 12, string $search = '', string $filter = 'all', ?int $statusId = null, ?int $clientId = null, string $sort = 'health-asc'): LengthAwarePaginator
    {
        $eagerLoads = [
            'client',
            'uptimeMonitor',
            'uptimeMonitor.incidents' => fn ($q) => $q->orderByDesc('started_at')->limit(10),
            'performanceMonitor',
            'backupConfig',
            'latestCompletedBackup',
            'sitePlugins' => fn ($q) => $q->where('has_update', true),
            'siteThemes' => fn ($q) => $q->where('has_update', true),
            'analyticsConnection',
            'searchConsoleConnection',
            'reportSchedules' => fn ($q) => $q->where('is_active', true),
            'healthState',
        ];

        $eagerLoads[] = 'siteStatus';

        $query = Site::with($eagerLoads)
            ->withCount([
                'sitePlugins',
                'sitePlugins as plugins_with_updates_count' => fn ($q) => $q->where('has_update', true),
                'siteThemes as themes_with_updates_count' => fn ($q) => $q->where('has_update', true),
                'siteUsers',
                'backups',
                'reportSchedules',
            ]);

        // Tenant scoping: non-admins only see their own / assigned sites.
        $query = $this->scopeSite($query, $this->accessibleSiteIds());

        if ($search !== '') {
            $escaped = '%'.$this->escapeLike($search).'%';
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'ilike', $escaped)
                    ->orWhere('url', 'ilike', $escaped);
            });
        }

        if ($filter === 'healthy') {
            $query->where('health_score', '>=', 90)->where('is_up', true);
        } elseif ($filter === 'warning') {
            $query->whereBetween('health_score', [70, 89])->where('is_up', true);
        } elseif ($filter === 'critical') {
            $query->where(function ($q) {
                $q->where('health_score', '<', 70)
                    ->orWhere('is_up', false);
            });
        }

        if ($statusId) {
            $query->where('site_status_id', $statusId);
        }

        if ($clientId) {
            $query->where('client_id', $clientId);
        }

        match ($sort) {
            'name-asc' => $query->reorder('name', 'asc'),
            'name-desc' => $query->reorder('name', 'desc'),
            'health-asc' => $query->reorder()->orderByRaw('COALESCE(health_score, 0) ASC'),
            'health-desc' => $query->reorder()->orderByRaw('COALESCE(health_score, 0) DESC'),
            default => null,
        };

        return $query->paginate($perPage);
    }

    public function getUptimeOverview(): array
    {
        $ids = $this->accessibleSiteIds();

        $monitors = $this->scopeRelated(UptimeMonitor::query(), $ids)->with('site')->get();

        $up = $monitors->where('current_state', MonitorState::Up)->count();
        $down = $monitors->where('current_state', MonitorState::Down)->count();
        $degraded = $monitors->where('current_state', MonitorState::Degraded)->count();

        $avgUptime = $monitors->whereNotNull('uptime_30d')->avg('uptime_30d');

        $recentIncidents = UptimeIncident::whereHas('monitor.site', fn ($q) => $ids !== null ? $q->whereIn('id', $ids) : $q)
            ->with('monitor.site')
            ->orderByDesc('started_at')
            ->limit(5)
            ->get();

        return [
            'up' => $up,
            'down' => $down,
            'degraded' => $degraded,
            'avg_uptime_30d' => $avgUptime ? round($avgUptime, 2) : null,
            'recent_incidents' => $recentIncidents,
        ];
    }

    public function getRecentActivity(int $limit = 15): \Illuminate\Database\Eloquent\Collection
    {
        return $this->scopeRelated(ActivityLog::query(), $this->accessibleSiteIds())
            ->with('site')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    /**
     * @param  array<int>|null  $ids
     */
    private function getBackupCounts(?array $ids = null): array
    {
        return [
            'backups_today' => $this->scopeRelated(Backup::query(), $ids)
                ->where('status', BackupStatus::Completed)
                ->whereDate('completed_at', today())
                ->count(),
            'failed_backups' => $this->scopeRelated(Backup::query(), $ids)
                ->where('status', BackupStatus::Failed)
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'total_storage_bytes' => $this->scopeRelated(Backup::query(), $ids)
                ->where('status', BackupStatus::Completed)
                ->sum('file_size'),
        ];
    }

    public function getSummaryStats(): array
    {
        return $this->cache()->remember($this->scopedKey('dashboard:summary_stats'), self::CACHE_TTL, fn () => $this->computeSummaryStats());
    }

    private function computeSummaryStats(): array
    {
        $stats = $this->getStats();

        return [
            'backups_today' => $stats['backups_today'],
            'failed_backups' => $stats['failed_backups'],
            'total_storage' => $stats['backup_storage_bytes'],
            'pending_updates' => $stats['pending_updates'],
        ];
    }

    public function getHealthDistribution(): array
    {
        return $this->cache()->remember($this->scopedKey('dashboard:health_distribution'), self::CACHE_TTL, fn () => $this->computeHealthDistribution());
    }

    private function computeHealthDistribution(): array
    {
        $healthy = HealthLevel::HEALTHY_THRESHOLD;
        $warning = HealthLevel::WARNING_THRESHOLD;

        $counts = $this->scopeSite(Site::query(), $this->accessibleSiteIds())
            ->selectRaw("
                SUM(CASE WHEN health_score >= {$healthy} AND is_up = true THEN 1 ELSE 0 END) as healthy,
                SUM(CASE WHEN health_score >= {$warning} AND health_score < {$healthy} AND is_up = true THEN 1 ELSE 0 END) as warning,
                SUM(CASE WHEN health_score < {$warning} AND is_up = true THEN 1 ELSE 0 END) as critical,
                SUM(CASE WHEN is_up = false THEN 1 ELSE 0 END) as down
            ")
            ->first();

        return [
            'labels' => ['Healthy', 'Warning', 'Critical', 'Down'],
            'values' => [
                (int) ($counts->healthy ?? 0),
                (int) ($counts->warning ?? 0),
                (int) ($counts->critical ?? 0),
                (int) ($counts->down ?? 0),
            ],
            'colors' => ['#10b981', '#f59e0b', '#ef4444', '#991b1b'],
        ];
    }

    public function getSitesNeedingAttention(int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return $this->scopeSite(Site::with([
            'uptimeMonitor',
            'latestCompletedBackup',
            'client',
        ]), $this->accessibleSiteIds())
            ->where(function ($query) {
                $query->where('health_score', '<', 70)
                    ->orWhere('is_up', false)
                    ->orWhereDoesntHave('latestCompletedBackup')
                    ->orWhereHas('latestCompletedBackup', function ($q) {
                        $q->where('completed_at', '<=', now()->subDays(7));
                    })
                    ->orWhereNotNull('core_update_version');
            })
            ->orderByRaw('CASE WHEN is_up = false THEN 0 ELSE 1 END')
            ->orderByRaw('COALESCE(health_score, 0) ASC')
            ->limit($limit)
            ->get();
    }

    /**
     * Return sites that have active issues, each with a list of human-readable issue labels.
     *
     * @return array<int, array{site_id: int, site_name: string, site_url: string, issues: list<string>}>
     */
    public function getSitesWithIssues(int $limit = 10): array
    {
        $ids = $this->accessibleSiteIds();

        // Gather site IDs for each issue category up front to avoid N+1 queries.
        $downIds = $this->scopeSite(Site::where('is_up', false), $ids)->pluck('id')->flip();

        $failedBackupIds = $this->scopeRelated(Backup::query(), $ids)
            ->where('status', BackupStatus::Failed)
            ->where('created_at', '>=', now()->subDay())
            ->pluck('site_id')
            ->unique()
            ->flip();

        $fatalErrorCounts = $this->scopeRelated(PhpErrorLog::unresolved()->fatal(), $ids)
            ->selectRaw('site_id, COUNT(*) as cnt')
            ->groupBy('site_id')
            ->pluck('cnt', 'site_id');

        $dnsChangedIds = $this->scopeRelated(\App\Models\DnsMonitor::where('has_changes', true), $ids)
            ->pluck('site_id')
            ->flip();

        $vulnCounts = $this->scopeRelated(VulnerabilityAlert::active(), $ids)
            ->selectRaw('site_id, COUNT(*) as cnt')
            ->groupBy('site_id')
            ->pluck('cnt', 'site_id');

        $allSiteIds = collect($downIds->keys())
            ->merge($failedBackupIds->keys())
            ->merge($fatalErrorCounts->keys())
            ->merge($dnsChangedIds->keys())
            ->merge($vulnCounts->keys())
            ->unique();

        if ($allSiteIds->isEmpty()) {
            return [];
        }

        $sites = Site::whereIn('id', $allSiteIds)
            ->orderByRaw('CASE WHEN is_up = false THEN 0 ELSE 1 END')
            ->orderByRaw('COALESCE(health_score, 0) ASC')
            ->limit($limit)
            ->get(['id', 'name', 'url']);

        $result = [];
        foreach ($sites as $site) {
            $issues = [];

            if ($downIds->has($site->id)) {
                $issues[] = 'Down';
            }
            if ($failedBackupIds->has($site->id)) {
                $issues[] = 'Backup failed';
            }
            if ($fatalErrorCounts->has($site->id)) {
                $count = (int) $fatalErrorCounts->get($site->id);
                $issues[] = $count === 1 ? '1 fatal error' : "{$count} fatal errors";
            }
            if ($dnsChangedIds->has($site->id)) {
                $issues[] = 'DNS changed';
            }
            if ($vulnCounts->has($site->id)) {
                $count = (int) $vulnCounts->get($site->id);
                $issues[] = $count === 1 ? '1 vulnerability' : "{$count} vulnerabilities";
            }

            if (! empty($issues)) {
                $result[] = [
                    'site_id' => $site->id,
                    'site_name' => $site->name,
                    'site_url' => $site->url,
                    'issues' => $issues,
                ];
            }
        }

        return $result;
    }

    public function getBackupStatus(): array
    {
        return $this->cache()->remember($this->scopedKey('dashboard:backup_status'), self::CACHE_TTL, fn () => $this->computeBackupStatus());
    }

    private function computeBackupStatus(): array
    {
        $ids = $this->accessibleSiteIds();
        $backups = $this->getBackupCounts($ids);

        $sitesWithoutBackup = $this->scopeSite(Site::query(), $ids)
            ->where(fn ($q) => $q
                ->whereDoesntHave('latestCompletedBackup')
                ->orWhereHas('latestCompletedBackup', function ($q) {
                    $q->where('completed_at', '<=', now()->subDays(7));
                })
            )
            ->count();

        return [
            'backups_today' => $backups['backups_today'],
            'failed_backups' => $backups['failed_backups'],
            'total_storage_gb' => $backups['total_storage_bytes'] ? round($backups['total_storage_bytes'] / 1024 / 1024 / 1024, 2) : 0,
            'sites_without_backup' => $sitesWithoutBackup,
        ];
    }
}
