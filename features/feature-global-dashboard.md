# SimpleAd Manager — Feature Spec: Global Dashboard

---

## Overview

The main dashboard at `/dashboard` that shows a comprehensive overview of all managed sites. Displays aggregate statistics, alerts, recent activity, and quick access to sites that need attention. This is the first page users see after login.

---

## PART 1: DASHBOARD SECTIONS

### 1.1 Top Stats Bar

Quick aggregate numbers across all sites:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐  ┌──────────┐     │
│  │    12    │  │    11    │  │     1    │  │    23    │  │     3    │     │
│  │  Sites   │  │    Up    │  │   Down   │  │ Updates  │  │  Alerts  │     │
│  │  Total   │  │   🟢     │  │   🔴     │  │ Pending  │  │  Active  │     │
│  └──────────┘  └──────────┘  └──────────┘  └──────────┘  └──────────┘     │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 1.2 Alerts / Attention Required

Sites that need immediate attention:

```
┌─ Needs Attention ──────────────────────────────────────────────────────────┐
│                                                                             │
│  🔴 client-site.ro is DOWN                                    5 min ago   │
│     Last checked: Feb 3, 10:45 — HTTP 503                    [View Site]  │
│                                                                             │
│  🟡 simplead.ro — SSL expires in 7 days                                   │
│     Expires: Feb 10, 2026 — Let's Encrypt                    [View Site]  │
│                                                                             │
│  🟡 shop.example.com — 5 broken links detected                            │
│     Last scan: 2 hours ago                                   [View Site]  │
│                                                                             │
│  🟡 blog.client.ro — Backup failed                           Yesterday   │
│     Error: Dropbox connection timeout                        [View Site]  │
│                                                                             │
│  🔵 3 sites have pending updates                              [View All]  │
│                                                                             │
└─────────────────────────────────────────────────────────────────────────────┘
```

Alert types (priority order):
1. 🔴 Site DOWN (critical)
2. 🔴 SSL expired (critical)
3. 🔴 Domain expired (critical)
4. 🟡 SSL expiring soon (<14 days)
5. 🟡 Domain expiring soon (<30 days)
6. 🟡 Backup failed
7. 🟡 Broken links detected (>5)
8. 🟡 Performance score dropped significantly
9. 🔵 Updates available

### 1.3 Sites Overview Grid

All sites with quick status indicators:

```
┌─ All Sites ──────────────────────────────────────────────────── [+ Add Site]┐
│                                                                              │
│  Filter: [All ▼] [Status ▼] [Client ▼]    Sort: [Name ▼]    🔍 Search...  │
│                                                                              │
│  ┌─────────────────────────┐  ┌─────────────────────────┐  ┌──────────────┐│
│  │ 🟢 simplead.ro          │  │ 🟢 shop.client.ro       │  │ 🔴 old-site  ││
│  │                         │  │                         │  │              ││
│  │ ⬆ 99.9%  🔒 41d  📦 2h  │  │ ⬆ 99.8%  🔒 120d  📦 5h │  │ ⬆ DOWN      ││
│  │ ⚡ 71/97  🔗 0           │  │ ⚡ 85/96   🔗 2          │  │ 🔒 7d ⚠     ││
│  │                         │  │                         │  │              ││
│  │ Client: —               │  │ Client: Acme Corp      │  │ Client: —    ││
│  └─────────────────────────┘  └─────────────────────────┘  └──────────────┘│
│                                                                              │
│  Legend: ⬆ Uptime  🔒 SSL days  📦 Last backup  ⚡ Perf M/D  🔗 Broken links│
└──────────────────────────────────────────────────────────────────────────────┘
```

### 1.4 Uptime Overview

Mini uptime status for all sites:

```
┌─ Uptime Status ────────────────────────────────────────────────────────────┐
│                                                                             │
│  Site                    │ Status │ Uptime 24h │ Uptime 30d │ Response    │
│ ─────────────────────────────────────────────────────────────────────────  │
│  simplead.ro             │ 🟢 Up  │ 100%       │ 99.95%     │ 245 ms      │
│  shop.client.ro          │ 🟢 Up  │ 100%       │ 99.87%     │ 312 ms      │
│  blog.example.com        │ 🟢 Up  │ 99.8%      │ 99.92%     │ 189 ms      │
│  old-site.ro             │ 🔴 Down│ 95.2%      │ 98.45%     │ — ms        │
│  api.client.com          │ 🟢 Up  │ 100%       │ 100%       │ 87 ms       │
│                                                                             │
│                                               [View Full Uptime Dashboard]  │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 1.5 Recent Activity Feed

Timeline of recent events across all sites:

```
┌─ Recent Activity ──────────────────────────────────────────────────────────┐
│                                                                             │
│  🔄 simplead.ro — WooCommerce updated (8.5 → 8.6)              10 min ago │
│  📦 shop.client.ro — Backup completed (245 MB)                 25 min ago │
│  🔴 old-site.ro — Site went DOWN                                1 hour ago │
│  ✅ blog.example.com — All 3 plugins updated                   2 hours ago │
│  📊 simplead.ro — Performance test: Mobile 71, Desktop 97      3 hours ago │
│  🔍 shop.client.ro — Link scan completed (1,245 links)         4 hours ago │
│  🟢 old-site.ro — Site recovered (was down 5 min)              5 hours ago │
│  📧 client-site.ro — Monthly report sent                       Yesterday  │
│                                                                             │
│                                                    [View All Activity →]   │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 1.6 Quick Stats Cards

Summary cards for key metrics:

```
┌─ Summary ──────────────────────────────────────────────────────────────────┐
│                                                                             │
│  ┌─ Backups ─────────────┐  ┌─ SSL Certificates ─────┐  ┌─ Performance ──┐│
│  │                       │  │                        │  │                 ││
│  │  12 sites configured  │  │  10 valid              │  │  Avg Mobile: 72││
│  │  8 backed up today    │  │  1 expiring soon       │  │  Avg Desktop: 91│
│  │  156 GB total storage │  │  1 expired             │  │                 ││
│  │                       │  │                        │  │  3 need work   ││
│  │  [Manage Backups]     │  │  [View Certificates]   │  │  [View All]    ││
│  └───────────────────────┘  └────────────────────────┘  └─────────────────┘│
│                                                                             │
│  ┌─ Updates ─────────────┐  ┌─ Domains ──────────────┐  ┌─ Links ────────┐│
│  │                       │  │                        │  │                 ││
│  │  23 updates pending   │  │  11 active             │  │  15,234 checked││
│  │  across 5 sites       │  │  1 expiring in 30d     │  │  18 broken     ││
│  │                       │  │  0 expired             │  │  across 3 sites││
│  │  [Update All]         │  │  [View Domains]        │  │  [View All]    ││
│  └───────────────────────┘  └────────────────────────┘  └─────────────────┘│
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## PART 2: DATABASE — ACTIVITY LOG

### Migration: `activity_logs`

```php
Schema::create('activity_logs', function (Blueprint $table) {
    $table->id();
    $table->foreignId('site_id')->nullable()->constrained()->nullOnDelete();
    $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
    
    $table->string('type'); // update, backup, uptime_down, uptime_up, ssl_expiring, performance_test, link_scan, report_sent, etc.
    $table->string('severity')->default('info'); // info, warning, error, critical
    $table->string('title');
    $table->text('description')->nullable();
    $table->json('metadata')->nullable(); // additional data (plugin name, versions, etc.)
    
    $table->string('icon')->nullable(); // emoji or icon class
    $table->string('url')->nullable(); // link to relevant page
    
    $table->timestamp('created_at');
    
    $table->index(['site_id', 'created_at']);
    $table->index(['type', 'created_at']);
    $table->index(['severity', 'created_at']);
});
```

### Activity Logger Service

```php
// app/Services/ActivityLogger.php

class ActivityLogger
{
    public static function log(
        string $type,
        string $title,
        ?string $description = null,
        ?Site $site = null,
        string $severity = 'info',
        ?array $metadata = null,
        ?string $icon = null,
        ?string $url = null
    ): ActivityLog {
        return ActivityLog::create([
            'site_id' => $site?->id,
            'user_id' => auth()->id(),
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'description' => $description,
            'metadata' => $metadata,
            'icon' => $icon ?? self::getDefaultIcon($type),
            'url' => $url,
        ]);
    }

    public static function siteDown(Site $site, string $reason): ActivityLog
    {
        return self::log(
            'uptime_down',
            "{$site->name} is DOWN",
            $reason,
            $site,
            'critical',
            ['reason' => $reason],
            '🔴',
            route('sites.uptime', $site)
        );
    }

    public static function siteUp(Site $site, int $downtimeMinutes): ActivityLog
    {
        return self::log(
            'uptime_up',
            "{$site->name} recovered",
            "Was down for {$downtimeMinutes} minutes",
            $site,
            'info',
            ['downtime_minutes' => $downtimeMinutes],
            '🟢',
            route('sites.uptime', $site)
        );
    }

    public static function backupCompleted(Site $site, Backup $backup): ActivityLog
    {
        $size = number_format($backup->file_size / 1024 / 1024, 1) . ' MB';
        return self::log(
            'backup_completed',
            "{$site->name} — Backup completed",
            "Size: {$size}",
            $site,
            'info',
            ['backup_id' => $backup->id, 'size' => $backup->file_size],
            '📦',
            route('sites.backups', $site)
        );
    }

    public static function backupFailed(Site $site, string $error): ActivityLog
    {
        return self::log(
            'backup_failed',
            "{$site->name} — Backup failed",
            $error,
            $site,
            'error',
            ['error' => $error],
            '❌',
            route('sites.backups', $site)
        );
    }

    public static function pluginUpdated(Site $site, string $plugin, string $from, string $to): ActivityLog
    {
        return self::log(
            'plugin_updated',
            "{$site->name} — {$plugin} updated",
            "{$from} → {$to}",
            $site,
            'info',
            ['plugin' => $plugin, 'from' => $from, 'to' => $to],
            '🔄',
            route('sites.updates', $site)
        );
    }

    public static function performanceTest(Site $site, int $mobile, int $desktop): ActivityLog
    {
        return self::log(
            'performance_test',
            "{$site->name} — Performance test completed",
            "Mobile: {$mobile}, Desktop: {$desktop}",
            $site,
            'info',
            ['mobile' => $mobile, 'desktop' => $desktop],
            '📊',
            route('sites.performance', $site)
        );
    }

    public static function linkScanCompleted(Site $site, int $total, int $broken): ActivityLog
    {
        return self::log(
            'link_scan',
            "{$site->name} — Link scan completed",
            "{$total} links checked, {$broken} broken",
            $site,
            $broken > 0 ? 'warning' : 'info',
            ['total' => $total, 'broken' => $broken],
            '🔍',
            route('sites.links', $site)
        );
    }

    public static function reportSent(Site $site, Report $report, array $recipients): ActivityLog
    {
        return self::log(
            'report_sent',
            "{$site->name} — Report sent",
            "Sent to " . count($recipients) . " recipients",
            $site,
            'info',
            ['report_id' => $report->id, 'recipients' => $recipients],
            '📧',
            route('sites.reports', $site)
        );
    }

    private static function getDefaultIcon(string $type): string
    {
        return match($type) {
            'uptime_down' => '🔴',
            'uptime_up' => '🟢',
            'backup_completed' => '📦',
            'backup_failed' => '❌',
            'plugin_updated', 'theme_updated', 'core_updated' => '🔄',
            'performance_test' => '📊',
            'link_scan' => '🔍',
            'report_sent' => '📧',
            'ssl_expiring' => '🔒',
            'domain_expiring' => '🌐',
            default => '📝',
        };
    }
}
```

---

## PART 3: DASHBOARD SERVICE

```php
// app/Services/DashboardService.php

class DashboardService
{
    public function getStats(): array
    {
        return [
            'sites_total' => Site::count(),
            'sites_up' => Site::whereHas('uptimeMonitor', fn($q) => $q->where('current_state', 'up'))->count(),
            'sites_down' => Site::whereHas('uptimeMonitor', fn($q) => $q->where('current_state', 'down'))->count(),
            'updates_pending' => SitePlugin::where('has_update', true)->count() + SiteTheme::where('has_update', true)->count(),
            'sites_with_updates' => Site::whereHas('plugins', fn($q) => $q->where('has_update', true))->count(),
            'alerts_count' => $this->getAlertsCount(),
        ];
    }

    public function getAlerts(): Collection
    {
        $alerts = collect();

        // Sites DOWN (critical)
        Site::whereHas('uptimeMonitor', fn($q) => $q->where('current_state', 'down'))
            ->with('uptimeMonitor')
            ->get()
            ->each(function ($site) use ($alerts) {
                $alerts->push([
                    'type' => 'site_down',
                    'severity' => 'critical',
                    'icon' => '🔴',
                    'title' => "{$site->name} is DOWN",
                    'description' => 'Last checked: ' . $site->uptimeMonitor->last_checked_at?->diffForHumans(),
                    'site' => $site,
                    'url' => route('sites.uptime', $site),
                    'timestamp' => $site->uptimeMonitor->last_checked_at,
                ]);
            });

        // SSL expired (critical)
        Site::whereHas('sslCertificate', fn($q) => $q->where('status', 'expired'))
            ->with('sslCertificate')
            ->get()
            ->each(function ($site) use ($alerts) {
                $alerts->push([
                    'type' => 'ssl_expired',
                    'severity' => 'critical',
                    'icon' => '🔴',
                    'title' => "{$site->name} — SSL certificate expired",
                    'description' => 'Expired on ' . $site->sslCertificate->expires_at?->format('M d, Y'),
                    'site' => $site,
                    'url' => route('sites.security', $site),
                    'timestamp' => $site->sslCertificate->expires_at,
                ]);
            });

        // SSL expiring soon (warning)
        Site::whereHas('sslCertificate', fn($q) => $q->where('status', 'expiring_soon')->where('days_remaining', '<=', 14))
            ->with('sslCertificate')
            ->get()
            ->each(function ($site) use ($alerts) {
                $alerts->push([
                    'type' => 'ssl_expiring',
                    'severity' => 'warning',
                    'icon' => '🟡',
                    'title' => "{$site->name} — SSL expires in {$site->sslCertificate->days_remaining} days",
                    'description' => 'Expires: ' . $site->sslCertificate->expires_at?->format('M d, Y'),
                    'site' => $site,
                    'url' => route('sites.security', $site),
                    'timestamp' => now(),
                ]);
            });

        // Backup failed (warning)
        Site::whereHas('backupConfig', fn($q) => $q->where('last_backup_status', 'failed'))
            ->with('backupConfig')
            ->get()
            ->each(function ($site) use ($alerts) {
                $lastBackup = Backup::where('site_id', $site->id)->where('status', 'failed')->latest()->first();
                $alerts->push([
                    'type' => 'backup_failed',
                    'severity' => 'warning',
                    'icon' => '🟡',
                    'title' => "{$site->name} — Backup failed",
                    'description' => $lastBackup?->error_message ? Str::limit($lastBackup->error_message, 60) : 'Unknown error',
                    'site' => $site,
                    'url' => route('sites.backups', $site),
                    'timestamp' => $lastBackup?->created_at ?? now(),
                ]);
            });

        // Broken links (warning)
        Site::whereHas('linkMonitor', fn($q) => $q->where('broken_links', '>', 5))
            ->with('linkMonitor')
            ->get()
            ->each(function ($site) use ($alerts) {
                $alerts->push([
                    'type' => 'broken_links',
                    'severity' => 'warning',
                    'icon' => '🟡',
                    'title' => "{$site->name} — {$site->linkMonitor->broken_links} broken links",
                    'description' => 'Last scan: ' . $site->linkMonitor->last_scan_at?->diffForHumans(),
                    'site' => $site,
                    'url' => route('sites.links', $site),
                    'timestamp' => $site->linkMonitor->last_scan_at,
                ]);
            });

        // Sites with updates (info)
        $sitesWithUpdates = Site::whereHas('plugins', fn($q) => $q->where('has_update', true))->count();
        if ($sitesWithUpdates > 0) {
            $alerts->push([
                'type' => 'updates_available',
                'severity' => 'info',
                'icon' => '🔵',
                'title' => "{$sitesWithUpdates} sites have pending updates",
                'description' => null,
                'site' => null,
                'url' => route('updates.index'),
                'timestamp' => now(),
            ]);
        }

        // Sort by severity then timestamp
        $severityOrder = ['critical' => 0, 'warning' => 1, 'info' => 2];
        return $alerts->sortBy([
            fn($a, $b) => $severityOrder[$a['severity']] <=> $severityOrder[$b['severity']],
            fn($a, $b) => $b['timestamp'] <=> $a['timestamp'],
        ])->values();
    }

    public function getSitesOverview(): Collection
    {
        return Site::with([
            'uptimeMonitor',
            'sslCertificate',
            'performanceMonitor',
            'linkMonitor',
            'backupConfig',
            'client',
        ])
        ->withCount(['plugins as pending_updates_count' => fn($q) => $q->where('has_update', true)])
        ->orderBy('name')
        ->get();
    }

    public function getUptimeOverview(): Collection
    {
        return Site::whereHas('uptimeMonitor')
            ->with('uptimeMonitor')
            ->get()
            ->map(fn($site) => [
                'site' => $site,
                'status' => $site->uptimeMonitor->current_state,
                'uptime_24h' => $site->uptimeMonitor->uptime_24h,
                'uptime_30d' => $site->uptimeMonitor->uptime_30d,
                'response_time' => $site->uptimeMonitor->avg_response_time,
            ]);
    }

    public function getRecentActivity(int $limit = 15): Collection
    {
        return ActivityLog::with('site')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    public function getSummaryStats(): array
    {
        return [
            'backups' => [
                'configured' => Site::whereHas('backupConfig', fn($q) => $q->where('is_enabled', true))->count(),
                'completed_today' => Backup::where('status', 'completed')->whereDate('created_at', today())->count(),
                'total_storage' => Backup::where('status', 'completed')->sum('file_size'),
            ],
            'ssl' => [
                'valid' => SslCertificate::where('status', 'valid')->count(),
                'expiring_soon' => SslCertificate::where('status', 'expiring_soon')->count(),
                'expired' => SslCertificate::where('status', 'expired')->count(),
            ],
            'performance' => [
                'avg_mobile' => (int) PerformanceMonitor::whereNotNull('latest_mobile_score')->avg('latest_mobile_score'),
                'avg_desktop' => (int) PerformanceMonitor::whereNotNull('latest_desktop_score')->avg('latest_desktop_score'),
                'poor_count' => PerformanceMonitor::where('latest_mobile_score', '<', 50)->count(),
            ],
            'updates' => [
                'total_pending' => SitePlugin::where('has_update', true)->count() + SiteTheme::where('has_update', true)->count(),
                'sites_with_updates' => Site::whereHas('plugins', fn($q) => $q->where('has_update', true))->count(),
            ],
            'domains' => [
                'active' => DomainMonitor::where('status', 'active')->count(),
                'expiring_soon' => DomainMonitor::where('status', 'expiring_soon')->count(),
                'expired' => DomainMonitor::where('status', 'expired')->count(),
            ],
            'links' => [
                'total_checked' => Link::count(),
                'broken_count' => Link::whereIn('status', ['broken', 'timeout', 'ssl_error', 'dns_error'])->where('is_dismissed', false)->count(),
                'sites_with_broken' => Site::whereHas('linkMonitor', fn($q) => $q->where('broken_links', '>', 0))->count(),
            ],
        ];
    }

    private function getAlertsCount(): int
    {
        return Site::whereHas('uptimeMonitor', fn($q) => $q->where('current_state', 'down'))->count()
            + SslCertificate::whereIn('status', ['expired', 'expiring_soon'])->where('days_remaining', '<=', 14)->count()
            + Site::whereHas('backupConfig', fn($q) => $q->where('last_backup_status', 'failed'))->count()
            + Site::whereHas('linkMonitor', fn($q) => $q->where('broken_links', '>', 5))->count();
    }
}
```

---

## PART 4: INTEGRATE ACTIVITY LOGGING

Add activity logging to existing jobs:

```php
// In CheckUptime job — when site goes down:
ActivityLogger::siteDown($site, $errorMessage);

// In CheckUptime job — when site recovers:
ActivityLogger::siteUp($site, $downtimeMinutes);

// In CreateBackup job — on success:
ActivityLogger::backupCompleted($site, $backup);

// In CreateBackup job — on failure:
ActivityLogger::backupFailed($site, $error);

// In plugin/theme update action:
ActivityLogger::pluginUpdated($site, $pluginName, $fromVersion, $toVersion);

// In RunPerformanceTest job:
ActivityLogger::performanceTest($site, $mobileScore, $desktopScore);

// In RunLinkScan job:
ActivityLogger::linkScanCompleted($site, $totalLinks, $brokenCount);

// In GenerateReport job — when sent:
ActivityLogger::reportSent($site, $report, $recipients);
```

---

## PART 5: GLOBAL PAGES

### 5.1 Global Uptime Page (`/uptime`)

Shows uptime status for all sites in one view:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Uptime Overview                                                             │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  ┌─ Summary ───────────────────────────────────────────────────────────────┐│
│  │  11 Up  •  1 Down  •  Average: 99.87%  •  Avg Response: 234 ms        ││
│  └─────────────────────────────────────────────────────────────────────────┘│
│                                                                              │
│  [All] [Up (11)] [Down (1)] [Degraded (0)]                  🔍 Search...   │
│                                                                              │
│  Site                    │ Status │ Uptime 24h │ 7d    │ 30d   │ Response  │
│ ────────────────────────────────────────────────────────────────────────── │
│  simplead.ro             │ 🟢 Up  │ 100%       │ 99.98%│ 99.95%│ 245 ms   │
│  shop.client.ro          │ 🟢 Up  │ 100%       │ 99.92%│ 99.87%│ 312 ms   │
│  blog.example.com        │ 🟢 Up  │ 99.8%      │ 99.85%│ 99.92%│ 189 ms   │
│  old-site.ro             │ 🔴 Down│ 95.2%      │ 97.5% │ 98.45%│ —        │
│  ...                                                                        │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 5.2 Global Updates Page (`/updates`)

Shows all pending updates across sites:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Pending Updates                                              [Update All]  │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  23 updates available across 5 sites                                        │
│                                                                              │
│  ┌─ simplead.ro (8 updates) ───────────────────────────────────────────────┐│
│  │  [ ] WooCommerce                      8.5.0 → 8.6.0          [Update]  ││
│  │  [ ] Yoast SEO                        22.1 → 22.3            [Update]  ││
│  │  [ ] Akismet                          4.2 → 4.2.1            [Update]  ││
│  │  ...                                               [Update All Site]   ││
│  └─────────────────────────────────────────────────────────────────────────┘│
│                                                                              │
│  ┌─ shop.client.ro (5 updates) ────────────────────────────────────────────┐│
│  │  [ ] WordPress Core                   6.4.3 → 6.5.0          [Update]  ││
│  │  [ ] WooCommerce                      8.4.0 → 8.6.0          [Update]  ││
│  │  ...                                               [Update All Site]   ││
│  └─────────────────────────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────────────────────────┘
```

### 5.3 Global Backups Page (`/backups`)

Shows recent backups across all sites:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Backups                                                    [Backup All]   │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  12 sites configured  •  8 backed up today  •  156 GB total storage        │
│                                                                              │
│  [Recent] [Failed] [By Site]                                🔍 Search...   │
│                                                                              │
│  Date          │ Site              │ Type     │ Size   │ Storage  │ Status │
│ ─────────────────────────────────────────────────────────────────────────  │
│  Today, 03:00  │ simplead.ro       │ Full     │ 245 MB │ Dropbox  │ ✅    │
│  Today, 03:00  │ shop.client.ro    │ Full     │ 512 MB │ Dropbox  │ ✅    │
│  Today, 03:00  │ blog.example.com  │ Database │ 12 MB  │ Local    │ ✅    │
│  Today, 03:00  │ old-site.ro       │ Full     │ —      │ Dropbox  │ ❌    │
│  Yesterday     │ simplead.ro       │ Full     │ 243 MB │ Dropbox  │ ✅    │
└─────────────────────────────────────────────────────────────────────────────┘
```

### 5.4 Activity Log Page (`/activity`)

Full activity history:

```
┌─────────────────────────────────────────────────────────────────────────────┐
│  Activity Log                                                               │
├─────────────────────────────────────────────────────────────────────────────┤
│                                                                              │
│  Filter: [All ▼] [Site ▼] [Type ▼]    Date: [Last 7 days ▼]  🔍 Search... │
│                                                                              │
│  Date/Time        │ Site              │ Activity                            │
│ ─────────────────────────────────────────────────────────────────────────  │
│  Feb 3, 10:45    │ simplead.ro       │ 🔄 WooCommerce updated (8.5→8.6)   │
│  Feb 3, 10:30    │ shop.client.ro    │ 📦 Backup completed (245 MB)        │
│  Feb 3, 10:15    │ old-site.ro       │ 🔴 Site went DOWN                   │
│  Feb 3, 09:00    │ blog.example.com  │ ✅ 3 plugins updated                │
│  Feb 3, 04:00    │ simplead.ro       │ 📊 Performance: Mobile 71, Desktop 97│
│  Feb 3, 02:00    │ shop.client.ro    │ 🔍 Link scan (1,245 links, 2 broken)│
│  Feb 2, 22:00    │ old-site.ro       │ 🟢 Site recovered                   │
│  ...                                                                        │
│                                                                              │
│  [Load More]                                                                │
└─────────────────────────────────────────────────────────────────────────────┘
```

---

## PART 6: UI COMPONENTS

```
app/Livewire/
├── Dashboard.php                        # Main dashboard page
│
├── Dashboard/
│   ├── StatsBar.php                     # Top stats cards
│   ├── AlertsPanel.php                  # Needs attention section
│   ├── SitesGrid.php                    # Sites overview grid
│   ├── UptimeOverview.php               # Uptime mini table
│   ├── ActivityFeed.php                 # Recent activity
│   └── SummaryCards.php                 # Quick stats cards
│
├── Global/
│   ├── GlobalUptime.php                 # /uptime page
│   ├── GlobalUpdates.php                # /updates page
│   ├── GlobalBackups.php                # /backups page
│   └── ActivityLog.php                  # /activity page
│
└── Components/
    ├── SiteCard.php                     # Individual site card in grid
    ├── AlertItem.php                    # Single alert row
    └── ActivityItem.php                 # Single activity row
```

---

## PART 7: ROUTES

```php
// Add to routes/web.php

// Dashboard
Route::get('/dashboard', Dashboard::class)->name('dashboard');

// Global pages
Route::get('/uptime', GlobalUptime::class)->name('uptime.index');
Route::get('/updates', GlobalUpdates::class)->name('updates.index');
Route::get('/backups', GlobalBackups::class)->name('backups.index');
Route::get('/activity', ActivityLog::class)->name('activity.index');
```

---

## PART 8: SIDEBAR UPDATE

Add new global pages to sidebar:

```
Dashboard          ← /dashboard (home icon)
──────────────
Sites              ← /sites
Uptime             ← /uptime (NEW - global uptime view)
Updates            ← /updates (NEW - global updates view)
Backups            ← /backups (NEW - global backups view)
──────────────
Activity           ← /activity (NEW - activity log)
Settings           ← /settings
```

---

## PART 9: IMPLEMENTATION CHECKLIST

### Database & Models
- [ ] Create migration: activity_logs
- [ ] Create model: ActivityLog

### Services
- [ ] Create ActivityLogger service with all log methods
- [ ] Create DashboardService with all data gathering methods

### Activity Integration
- [ ] Add ActivityLogger calls to CheckUptime job (down/up)
- [ ] Add ActivityLogger calls to CreateBackup job (success/failure)
- [ ] Add ActivityLogger calls to plugin/theme update actions
- [ ] Add ActivityLogger calls to RunPerformanceTest job
- [ ] Add ActivityLogger calls to RunLinkScan job
- [ ] Add ActivityLogger calls to GenerateReport job

### Dashboard Page
- [ ] Build main Dashboard Livewire component
- [ ] Build StatsBar component (sites total, up, down, updates, alerts)
- [ ] Build AlertsPanel component (needs attention list)
- [ ] Build SitesGrid component (site cards with status indicators)
- [ ] Build UptimeOverview component (mini table)
- [ ] Build ActivityFeed component (recent activity)
- [ ] Build SummaryCards component (backups, SSL, performance, etc.)

### Global Pages
- [ ] Build GlobalUptime page (`/uptime`)
- [ ] Build GlobalUpdates page (`/updates`) with bulk update functionality
- [ ] Build GlobalBackups page (`/backups`)
- [ ] Build ActivityLog page (`/activity`) with filters

### Sidebar
- [ ] Add new global pages to sidebar navigation
- [ ] Update active state logic for new routes

### Polish
- [ ] Auto-refresh dashboard every 60 seconds (wire:poll.60s on critical components)
- [ ] Loading states for all sections
- [ ] Empty states when no data
- [ ] Responsive design for all dashboard components
