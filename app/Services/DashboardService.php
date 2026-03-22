<?php

namespace App\Services;

use App\Enums\BackupStatus;
use App\Enums\HealthLevel;
use App\Enums\MonitorState;
use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\Site;
use App\Models\SslCertificate;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
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

        $failedBackups = Backup::whereHas('site')
            ->where('status', BackupStatus::Failed)
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $sslExpiring = SslCertificate::whereHas('site')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(30))
            ->where('expires_at', '>', now())
            ->count();

        $totalStorageBytes = Backup::whereHas('site')
            ->where('status', BackupStatus::Completed)
            ->sum('file_size');

        $backupsToday = Backup::whereHas('site')
            ->where('status', BackupStatus::Completed)
            ->whereDate('completed_at', today())
            ->count();

        return [
            'total_sites' => $totalSites,
            'sites_down' => $sitesDown,
            'avg_uptime' => $avgUptime ? round($avgUptime, 2) : null,
            'avg_response_time' => $avgResponseTime ? (int) round($avgResponseTime) : null,
            'pending_updates' => $pendingPluginUpdates + $pendingThemeUpdates + $pendingCoreUpdates,
            'pending_plugin_updates' => $pendingPluginUpdates,
            'pending_theme_updates' => $pendingThemeUpdates,
            'pending_core_updates' => $pendingCoreUpdates,
            'failed_backups' => $failedBackups,
            'ssl_expiring' => $sslExpiring,
            'total_alerts' => $sitesDown + $sslExpiring + $failedBackups,
            'backup_storage_bytes' => $totalStorageBytes,
            'backups_today' => $backupsToday,
        ];
    }

    public static function invalidateCache(): void
    {
        Cache::forget('dashboard:stats');
        Cache::forget('dashboard:alerts');
        Cache::forget('dashboard:summary_stats');
        Cache::forget('dashboard:health_distribution');
        Cache::forget('dashboard:backup_status');
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

        // SSL — one alert per expired cert, one per expiring-soon cert
        $sslExpiring = SslCertificate::whereHas('site')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(14))
            ->with('site')
            ->get();

        foreach ($sslExpiring as $cert) {
            if ($cert->expires_at->isPast()) {
                $alerts[] = [
                    'key' => "ssl_expired_{$cert->site->id}",
                    'severity' => 'critical',
                    'icon' => 'shield',
                    'title' => "SSL expired for {$cert->site->name}",
                    'description' => null,
                    'url' => route('sites.security', $cert->site),
                    'timestamp' => $cert->updated_at,
                ];
            } else {
                $alerts[] = [
                    'key' => "ssl_expiring_{$cert->site->id}",
                    'severity' => 'warning',
                    'icon' => 'shield',
                    'title' => "SSL expiring soon for {$cert->site->name}",
                    'description' => "Expires {$cert->expires_at->diffForHumans()}",
                    'url' => route('sites.security', $cert->site),
                    'timestamp' => $cert->updated_at,
                ];
            }
        }

        // Backup failures (last 24h) — one alert per site (deduped)
        $failedBackups = Backup::whereHas('site')
            ->where('status', BackupStatus::Failed)
            ->where('created_at', '>=', now()->subDay())
            ->with('site')
            ->get();

        $failedBysite = $failedBackups->groupBy('site_id');
        foreach ($failedBysite as $siteId => $backups) {
            $site = $backups->first()->site;
            $alerts[] = [
                'key' => "backup_failed_{$site->id}",
                'severity' => 'critical',
                'icon' => 'hard-drive',
                'title' => "Backup failed for {$site->name}",
                'description' => null,
                'url' => route('sites.backups', $site),
                'timestamp' => $backups->max('created_at'),
                'action' => "retry_backup_{$site->id}",
            ];
        }

        // Sort: critical first, then warning, then by timestamp desc
        usort($alerts, function ($a, $b) {
            $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
            $aSev = $severityOrder[$a['severity']] ?? 2;
            $bSev = $severityOrder[$b['severity']] ?? 2;
            if ($aSev !== $bSev) {
                return $aSev - $bSev;
            }

            return ($b['timestamp'] ?? now()) <=> ($a['timestamp'] ?? now());
        });

        return $alerts;
    }

    public function getSitesOverview(int $perPage = 12, string $search = '', string $filter = 'all', ?int $statusId = null, ?int $clientId = null, string $sort = 'health-asc'): LengthAwarePaginator
    {
        $eagerLoads = [
            'client',
            'uptimeMonitor',
            'uptimeMonitor.incidents',
            'sslCertificate',
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
                    ->orWhere('domain', 'ilike', "%{$search}%");
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
            'manual' => $query->orderBy('sort_order', 'asc'),
            'name-asc' => $query->orderBy('name', 'asc'),
            'name-desc' => $query->orderBy('name', 'desc'),
            'health-asc' => $query->orderByRaw('COALESCE(health_score, 0) ASC'),
            'health-desc' => $query->orderByRaw('COALESCE(health_score, 0) DESC'),
            default => $query->orderBy('sort_order', 'asc'),
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
        $backups = $this->getBackupCounts();

        return [
            'backups_today' => $backups['backups_today'],
            'failed_backups' => $stats['failed_backups'],
            'total_storage' => $backups['total_storage_bytes'],
            'pending_updates' => $stats['pending_updates'],
            'ssl_expiring' => $stats['ssl_expiring'],
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
            'sslCertificate',
            'latestCompletedBackup',
            'client',
        ])
            ->where(function ($query) {
                $query->where('health_score', '<', 70)
                    ->orWhere('is_up', false)
                    ->orWhereHas('sslCertificate', function ($q) {
                        $q->whereNotNull('expires_at')
                            ->where('expires_at', '<=', now()->addDays(14));
                    })
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
