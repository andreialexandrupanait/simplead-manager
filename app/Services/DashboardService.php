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

    public function getStats(): array
    {
        return Cache::remember('dashboard:stats', self::CACHE_TTL, fn () => $this->computeStats());
    }

    private function computeStats(): array
    {
        $totalSites = Site::count();
        $sitesDown = Site::where('is_up', false)->count();

        $avgUptime = UptimeMonitor::whereHas('site')->whereNotNull('uptime_30d')->avg('uptime_30d');
        $avgResponseTime = UptimeMonitor::whereHas('site')
            ->whereNotNull('avg_response_time')
            ->where('avg_response_time', '>', 0)
            ->avg('avg_response_time');

        $pendingPluginUpdates = \App\Models\SitePlugin::whereHas('site')->where('has_update', true)->count();
        $pendingThemeUpdates = \App\Models\SiteTheme::whereHas('site')->where('has_update', true)->count();
        $pendingCoreUpdates = Site::whereNotNull('core_update_version')->count();

        $backups = $this->getBackupCounts();

        return [
            'total_sites' => $totalSites,
            'sites_down' => $sitesDown,
            'avg_uptime' => $avgUptime ? round((float) $avgUptime, 2) : null,
            'avg_response_time' => $avgResponseTime ? (int) round((float) $avgResponseTime) : null,
            'pending_updates' => $pendingPluginUpdates + $pendingThemeUpdates + $pendingCoreUpdates,
            'pending_plugin_updates' => $pendingPluginUpdates,
            'pending_theme_updates' => $pendingThemeUpdates,
            'pending_core_updates' => $pendingCoreUpdates,
            'failed_backups' => $backups['failed_backups'],
            'total_alerts' => $sitesDown + $backups['failed_backups'],
            'backup_storage_bytes' => $backups['total_storage_bytes'],
            'backups_today' => $backups['backups_today'],
        ];
    }

    public function getTrends(): array
    {
        return Cache::remember('dashboard:trends', self::CACHE_TTL, fn () => $this->computeTrends());
    }

    private function computeTrends(): array
    {
        // Uptime trend: compare 7-day average against 30-day average.
        // If the recent 7d average is higher than 30d it is improving; lower means degrading.
        $avg7d = UptimeMonitor::whereHas('site')
            ->whereNotNull('uptime_7d')
            ->avg('uptime_7d');

        $avg30d = UptimeMonitor::whereHas('site')
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
        $currentUpdates = \App\Models\SitePlugin::whereHas('site')->where('has_update', true)->count()
            + \App\Models\SiteTheme::whereHas('site')->where('has_update', true)->count()
            + Site::whereNotNull('core_update_version')->count();

        $previousUpdates = Cache::get('dashboard:trends:prev_updates');
        if ($previousUpdates === null) {
            Cache::put('dashboard:trends:prev_updates', $currentUpdates, 3600);
            $updatesTrend = 'neutral';
            $updatesDelta = null;
        } else {
            $updatesDelta = $currentUpdates - (int) $previousUpdates;
            $updatesTrend = $updatesDelta > 0 ? 'up' : ($updatesDelta < 0 ? 'down' : 'neutral');
            Cache::put('dashboard:trends:prev_updates', $currentUpdates, 3600);
        }

        // Failed backups trend: compare last 24 h against the prior 24–48 h window.
        $failedLast24h = Backup::whereHas('site')
            ->where('status', BackupStatus::Failed)
            ->where('created_at', '>=', now()->subHours(24))
            ->count();

        $failedPrev24h = Backup::whereHas('site')
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
        Cache::forget('dashboard:stats');
        Cache::forget('dashboard:alerts');
        Cache::forget('dashboard:summary_stats');
        Cache::forget('dashboard:health_distribution');
        Cache::forget('dashboard:backup_status');
        Cache::forget('dashboard:trends');
    }

    public function getAlerts(): array
    {
        return Cache::remember('dashboard:alerts', self::CACHE_TTL, fn () => $this->computeAlerts());
    }

    private function computeAlerts(): array
    {
        $alerts = [];

        // Sites down — one alert per site
        $sitesDown = Site::where('is_up', false)->with('uptimeMonitor')->get();
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
        $failedBackups = Backup::whereHas('site')
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
            'uptimeMonitor.incidents',
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

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")
                    ->orWhere('url', 'ilike', "%{$search}%");
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
        $monitors = UptimeMonitor::whereHas('site')->with('site')->get();

        $up = $monitors->where('current_state', MonitorState::Up)->count();
        $down = $monitors->where('current_state', MonitorState::Down)->count();
        $degraded = $monitors->where('current_state', MonitorState::Degraded)->count();

        $avgUptime = $monitors->whereNotNull('uptime_30d')->avg('uptime_30d');

        $recentIncidents = UptimeIncident::whereHas('monitor.site')
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
        return ActivityLog::whereHas('site')
            ->with('site')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    private function getBackupCounts(): array
    {
        return [
            'backups_today' => Backup::whereHas('site')
                ->where('status', BackupStatus::Completed)
                ->whereDate('completed_at', today())
                ->count(),
            'failed_backups' => Backup::whereHas('site')
                ->where('status', BackupStatus::Failed)
                ->where('created_at', '>=', now()->subDay())
                ->count(),
            'total_storage_bytes' => Backup::whereHas('site')
                ->where('status', BackupStatus::Completed)
                ->sum('file_size'),
        ];
    }

    public function getSummaryStats(): array
    {
        return Cache::remember('dashboard:summary_stats', self::CACHE_TTL, fn () => $this->computeSummaryStats());
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
        return Cache::remember('dashboard:health_distribution', self::CACHE_TTL, fn () => $this->computeHealthDistribution());
    }

    private function computeHealthDistribution(): array
    {
        $healthy = HealthLevel::HEALTHY_THRESHOLD;
        $warning = HealthLevel::WARNING_THRESHOLD;

        $counts = Site::query()
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
        return Site::with([
            'uptimeMonitor',
            'latestCompletedBackup',
            'client',
        ])
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
        // Gather site IDs for each issue category up front to avoid N+1 queries.
        $downIds = Site::where('is_up', false)->pluck('id')->flip();

        $failedBackupIds = Backup::whereHas('site')
            ->where('status', BackupStatus::Failed)
            ->where('created_at', '>=', now()->subDay())
            ->pluck('site_id')
            ->unique()
            ->flip();

        $fatalErrorCounts = PhpErrorLog::unresolved()->fatal()
            ->selectRaw('site_id, COUNT(*) as cnt')
            ->groupBy('site_id')
            ->pluck('cnt', 'site_id');

        $dnsChangedIds = \App\Models\DnsMonitor::where('has_changes', true)
            ->pluck('site_id')
            ->flip();

        $vulnCounts = VulnerabilityAlert::active()
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
        return Cache::remember('dashboard:backup_status', self::CACHE_TTL, fn () => $this->computeBackupStatus());
    }

    private function computeBackupStatus(): array
    {
        $backups = $this->getBackupCounts();

        $sitesWithoutBackup = Site::whereDoesntHave('latestCompletedBackup')
            ->orWhereHas('latestCompletedBackup', function ($q) {
                $q->where('completed_at', '<=', now()->subDays(7));
            })
            ->count();

        return [
            'backups_today' => $backups['backups_today'],
            'failed_backups' => $backups['failed_backups'],
            'total_storage_gb' => $backups['total_storage_bytes'] ? round($backups['total_storage_bytes'] / 1024 / 1024 / 1024, 2) : 0,
            'sites_without_backup' => $sitesWithoutBackup,
        ];
    }
}
