<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Backup;
use App\Models\Client;
use App\Models\Site;
use App\Models\SslCertificate;
use App\Models\DomainMonitor;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;

class DashboardService
{
    public function getStats(): array
    {
        $totalSites = Site::count();
        $sitesUp = Site::where('is_up', true)->count();
        $sitesDown = Site::where('is_up', false)->count();
        $totalClients = Client::count();

        $avgUptime = UptimeMonitor::whereNotNull('uptime_30d')->avg('uptime_30d');
        $avgResponseTime = UptimeMonitor::whereNotNull('avg_response_time')
            ->where('avg_response_time', '>', 0)
            ->avg('avg_response_time');

        return [
            'total_sites' => $totalSites,
            'sites_up' => $sitesUp,
            'sites_down' => $sitesDown,
            'total_clients' => $totalClients,
            'avg_uptime' => $avgUptime ? round($avgUptime, 2) : null,
            'avg_response_time' => $avgResponseTime ? (int) round($avgResponseTime) : null,
        ];
    }

    public function getAlerts(): array
    {
        $groups = [];

        // Sites down — group all into one alert
        $sitesDown = Site::where('is_up', false)->with('uptimeMonitor')->get();
        if ($sitesDown->isNotEmpty()) {
            $names = $sitesDown->pluck('name')->toArray();
            $groups[] = [
                'key' => 'sites_down',
                'severity' => 'critical',
                'icon' => 'activity',
                'title' => $sitesDown->count() === 1
                    ? "{$names[0]} is down"
                    : "{$sitesDown->count()} sites are down",
                'description' => \Illuminate\Support\Str::limit(implode(', ', $names), 120),
                'count' => $sitesDown->count(),
                'items' => $sitesDown->pluck('id')->toArray(),
                'url' => route('uptime.index'),
                'timestamp' => $sitesDown->max(fn ($s) => $s->uptimeMonitor?->last_checked_at),
            ];
        }

        // SSL — group expired separate from expiring-soon
        $sslExpiring = SslCertificate::whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(14))
            ->with('site')
            ->get()
            ->filter(fn ($cert) => $cert->site);

        $sslExpired = $sslExpiring->filter(fn ($cert) => $cert->expires_at->isPast());
        $sslExpiringSoon = $sslExpiring->filter(fn ($cert) => !$cert->expires_at->isPast());

        if ($sslExpired->isNotEmpty()) {
            $names = $sslExpired->map(fn ($c) => $c->site->name)->toArray();
            $groups[] = [
                'key' => 'ssl_expired',
                'severity' => 'critical',
                'icon' => 'shield',
                'title' => $sslExpired->count() === 1
                    ? "SSL expired for {$names[0]}"
                    : "{$sslExpired->count()} SSL certificates expired",
                'description' => \Illuminate\Support\Str::limit(implode(', ', $names), 120),
                'count' => $sslExpired->count(),
                'items' => $sslExpired->map(fn ($c) => $c->site->id)->toArray(),
                'url' => route('uptime.index'),
                'timestamp' => $sslExpired->max('updated_at'),
            ];
        }

        if ($sslExpiringSoon->isNotEmpty()) {
            $names = $sslExpiringSoon->map(fn ($c) => $c->site->name)->toArray();
            $groups[] = [
                'key' => 'ssl_expiring',
                'severity' => 'warning',
                'icon' => 'shield',
                'title' => $sslExpiringSoon->count() === 1
                    ? "SSL expiring soon for {$names[0]}"
                    : "{$sslExpiringSoon->count()} SSL certificates expiring soon",
                'description' => \Illuminate\Support\Str::limit(implode(', ', $names), 120),
                'count' => $sslExpiringSoon->count(),
                'items' => $sslExpiringSoon->map(fn ($c) => $c->site->id)->toArray(),
                'url' => route('uptime.index'),
                'timestamp' => $sslExpiringSoon->max('updated_at'),
            ];
        }

        // Domains — group expired separate from expiring-soon
        $domainsExpiring = DomainMonitor::whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(30))
            ->with('site')
            ->get()
            ->filter(fn ($d) => $d->site);

        $domainsExpired = $domainsExpiring->filter(fn ($d) => $d->expires_at->isPast());
        $domainsExpiringSoon = $domainsExpiring->filter(fn ($d) => !$d->expires_at->isPast());

        if ($domainsExpired->isNotEmpty()) {
            $names = $domainsExpired->pluck('domain')->toArray();
            $groups[] = [
                'key' => 'domains_expired',
                'severity' => 'critical',
                'icon' => 'globe',
                'title' => $domainsExpired->count() === 1
                    ? "Domain expired: {$names[0]}"
                    : "{$domainsExpired->count()} domains expired",
                'description' => \Illuminate\Support\Str::limit(implode(', ', $names), 120),
                'count' => $domainsExpired->count(),
                'items' => $domainsExpired->map(fn ($d) => $d->site->id)->toArray(),
                'url' => route('uptime.index'),
                'timestamp' => $domainsExpired->max('updated_at'),
            ];
        }

        if ($domainsExpiringSoon->isNotEmpty()) {
            $names = $domainsExpiringSoon->pluck('domain')->toArray();
            $groups[] = [
                'key' => 'domains_expiring',
                'severity' => 'warning',
                'icon' => 'globe',
                'title' => $domainsExpiringSoon->count() === 1
                    ? "Domain expiring soon: {$names[0]}"
                    : "{$domainsExpiringSoon->count()} domains expiring soon",
                'description' => \Illuminate\Support\Str::limit(implode(', ', $names), 120),
                'count' => $domainsExpiringSoon->count(),
                'items' => $domainsExpiringSoon->map(fn ($d) => $d->site->id)->toArray(),
                'url' => route('uptime.index'),
                'timestamp' => $domainsExpiringSoon->max('updated_at'),
            ];
        }

        // Backup failures (last 24h) — group all with retry action
        $failedBackups = Backup::where('status', 'failed')
            ->where('created_at', '>=', now()->subDay())
            ->with('site')
            ->get()
            ->filter(fn ($b) => $b->site);

        if ($failedBackups->isNotEmpty()) {
            $siteNames = $failedBackups->map(fn ($b) => $b->site->name)->unique()->toArray();
            $siteIds = $failedBackups->map(fn ($b) => $b->site->id)->unique()->values()->toArray();
            $groups[] = [
                'key' => 'backup_failed',
                'severity' => 'critical',
                'icon' => 'hard-drive',
                'title' => $failedBackups->count() === 1
                    ? "Backup failed for {$siteNames[0]}"
                    : "{$failedBackups->count()} backup failures",
                'description' => \Illuminate\Support\Str::limit(implode(', ', $siteNames), 120),
                'count' => $failedBackups->count(),
                'items' => $siteIds,
                'url' => route('backups.index'),
                'timestamp' => $failedBackups->max('created_at'),
                'action' => 'retry_backups',
            ];
        }

        // Broken links (>5) — group all
        $brokenLinkSites = Site::whereHas('linkMonitor', function ($q) {
            $q->where('broken_links', '>', 5);
        })->with('linkMonitor')->get();

        if ($brokenLinkSites->isNotEmpty()) {
            $names = $brokenLinkSites->pluck('name')->toArray();
            $groups[] = [
                'key' => 'broken_links',
                'severity' => 'warning',
                'icon' => 'link',
                'title' => $brokenLinkSites->count() === 1
                    ? "Broken links on {$names[0]}"
                    : "{$brokenLinkSites->count()} sites with broken links",
                'description' => \Illuminate\Support\Str::limit(implode(', ', $names), 120),
                'count' => $brokenLinkSites->count(),
                'items' => $brokenLinkSites->pluck('id')->toArray(),
                'url' => route('sites.index'),
                'timestamp' => $brokenLinkSites->max(fn ($s) => $s->linkMonitor->last_scan_at),
            ];
        }

        // Sort: critical first, then warning, then by timestamp desc
        usort($groups, function ($a, $b) {
            $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
            $aSev = $severityOrder[$a['severity']] ?? 2;
            $bSev = $severityOrder[$b['severity']] ?? 2;
            if ($aSev !== $bSev) return $aSev - $bSev;
            return ($b['timestamp'] ?? now()) <=> ($a['timestamp'] ?? now());
        });

        return $groups;
    }

    public function getSitesOverview(int $limit = 0): \Illuminate\Database\Eloquent\Collection
    {
        $query = Site::with([
            'client',
            'uptimeMonitor',
            'uptimeMonitor.incidents',
            'sslCertificate',
            'domainMonitor',
            'performanceMonitor',
            'linkMonitor',
            'backupConfig',
            'latestCompletedBackup',
            'sitePlugins' => fn($q) => $q->where('has_update', true),
            'siteThemes' => fn($q) => $q->where('has_update', true),
            'analyticsConnection',
            'reportSchedules' => fn($q) => $q->where('is_active', true),
        ])
            ->withCount([
                'sitePlugins',
                'sitePlugins as plugins_with_updates_count' => fn($q) => $q->where('has_update', true),
                'siteThemes as themes_with_updates_count' => fn($q) => $q->where('has_update', true),
            ])
            ->orderByRaw('is_up ASC')
            ->orderByRaw('COALESCE(health_score, 0) ASC');

        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->get();
    }

    public function getUptimeOverview(): array
    {
        $monitors = UptimeMonitor::with('site')->get();

        $up = $monitors->where('current_state', 'up')->count();
        $down = $monitors->where('current_state', 'down')->count();
        $degraded = $monitors->where('current_state', 'degraded')->count();

        $avgUptime = $monitors->whereNotNull('uptime_30d')->avg('uptime_30d');

        $recentIncidents = UptimeIncident::with('monitor.site')
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
        return ActivityLog::with('site')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function getSummaryStats(): array
    {
        $backupsToday = Backup::where('status', 'completed')
            ->whereDate('completed_at', today())
            ->count();

        $failedBackups = Backup::where('status', 'failed')
            ->where('created_at', '>=', now()->subDay())
            ->count();

        $totalStorageBytes = Backup::where('status', 'completed')
            ->sum('file_size');

        $pendingPluginUpdates = \App\Models\SitePlugin::where('has_update', true)->count();
        $pendingThemeUpdates = \App\Models\SiteTheme::where('has_update', true)->count();
        $pendingCoreUpdates = Site::whereNotNull('core_update_version')->count();
        $pendingUpdates = $pendingPluginUpdates + $pendingThemeUpdates + $pendingCoreUpdates;

        $sslExpiring = SslCertificate::whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(30))
            ->where('expires_at', '>', now())
            ->count();

        $domainsExpiring = DomainMonitor::whereNotNull('expires_at')
            ->where('expires_at', '<=', now()->addDays(30))
            ->where('expires_at', '>', now())
            ->count();

        return [
            'backups_today' => $backupsToday,
            'failed_backups' => $failedBackups,
            'total_storage' => $totalStorageBytes,
            'pending_updates' => $pendingUpdates,
            'ssl_expiring' => $sslExpiring,
            'domains_expiring' => $domainsExpiring,
        ];
    }
}
