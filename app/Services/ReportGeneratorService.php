<?php

namespace App\Services;

use App\Models\AnalyticsCache;
use App\Models\Backup;
use App\Models\ReportTemplate;
use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Models\UpdateLog;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class ReportGeneratorService
{
    protected array $data = [];

    public function __construct(
        protected Site $site,
        protected ReportTemplate $template,
        protected Carbon $periodStart,
        protected Carbon $periodEnd,
    ) {}

    public function generate(): string
    {
        $this->gatherData();

        $pdf = Pdf::loadView('reports.maintenance-report', [
            'site' => $this->site,
            'template' => $this->template,
            'data' => $this->data,
            'periodStart' => $this->periodStart,
            'periodEnd' => $this->periodEnd,
        ]);

        $pdf->setPaper('a4');
        $pdf->setOptions([
            'isHtml5ParserEnabled' => true,
            'isRemoteEnabled' => true,
            'defaultFont' => 'DejaVu Sans',
            'isFontSubsettingEnabled' => true,
        ]);

        $directory = 'reports/' . $this->site->id;
        $fileName = 'report-' . $this->site->id . '-' . now()->format('Y-m-d-His') . '.pdf';
        $filePath = $directory . '/' . $fileName;

        Storage::disk('local')->makeDirectory($directory);
        Storage::disk('local')->put($filePath, $pdf->output());

        return $filePath;
    }

    public function getData(): array
    {
        return $this->data;
    }

    protected function gatherData(): void
    {
        $sections = $this->template->sections ?? [];

        if (in_array('overview', $sections)) {
            $this->data['overview'] = $this->gatherOverviewData();
        }

        if (in_array('updates', $sections)) {
            $this->data['updates'] = $this->gatherUpdatesData();
        }

        if (in_array('uptime', $sections)) {
            $this->data['uptime'] = $this->gatherUptimeData();
        }

        if (in_array('backups', $sections)) {
            $this->data['backups'] = $this->gatherBackupsData();
        }

        if (in_array('analytics', $sections)) {
            $this->data['analytics'] = $this->gatherAnalyticsData();
        }

        if (in_array('search_console', $sections)) {
            $this->data['search_console'] = $this->gatherSearchConsoleData();
        }

        if (in_array('performance', $sections)) {
            $this->data['performance'] = $this->gatherPerformanceData();
        }

        if (in_array('links', $sections)) {
            $this->data['links'] = $this->gatherLinksData();
        }
    }

    protected function gatherOverviewData(): array
    {
        $overview = [
            'site_name' => $this->site->name,
            'site_url' => $this->site->url,
            'wp_version' => $this->site->wp_version,
            'php_version' => $this->site->php_version,
            'health_score' => $this->site->health_score,
        ];

        // Updates count
        $overview['updates_count'] = UpdateLog::where('site_id', $this->site->id)
            ->whereBetween('performed_at', [$this->periodStart, $this->periodEnd])
            ->count();

        // Uptime
        $monitor = $this->site->uptimeMonitor;
        $overview['uptime_percentage'] = $monitor?->uptime_30d;
        $overview['incidents_count'] = $monitor
            ? UptimeIncident::where('monitor_id', $monitor->id)
                ->whereBetween('started_at', [$this->periodStart, $this->periodEnd])
                ->count()
            : 0;

        // Backups
        $overview['backups_count'] = Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$this->periodStart, $this->periodEnd])
            ->count();

        // Performance
        $perfMonitor = $this->site->performanceMonitor;
        $overview['mobile_score'] = $perfMonitor?->latest_mobile_score;
        $overview['desktop_score'] = $perfMonitor?->latest_desktop_score;

        // Analytics
        $analyticsCache = AnalyticsCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->latest('fetched_at')
            ->first();
        if ($analyticsCache) {
            $analyticsData = $analyticsCache->data ?? [];
            $overview['total_users'] = $analyticsData['total_users'] ?? null;
            $overview['total_sessions'] = $analyticsData['total_sessions'] ?? null;
            $overview['total_pageviews'] = $analyticsData['total_pageviews'] ?? null;
        }

        // Search Console
        $scCache = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->where('data_type', 'overview')
            ->latest('fetched_at')
            ->first();
        if ($scCache) {
            $scData = $scCache->data ?? [];
            $overview['total_clicks'] = $scData['total_clicks'] ?? null;
            $overview['total_impressions'] = $scData['total_impressions'] ?? null;
        }

        // Links
        $linkMonitor = $this->site->linkMonitor;
        $overview['broken_links'] = $linkMonitor?->broken_links ?? 0;
        $overview['total_links'] = $linkMonitor?->total_links ?? 0;

        return $overview;
    }

    protected function gatherUpdatesData(): array
    {
        $logs = UpdateLog::where('site_id', $this->site->id)
            ->whereBetween('performed_at', [$this->periodStart, $this->periodEnd])
            ->orderBy('performed_at', 'desc')
            ->get();

        return [
            'wp_version' => $this->site->wp_version,
            'core_updates' => $logs->where('type', 'core')->values()->toArray(),
            'plugin_updates' => $logs->where('type', 'plugin')->values()->toArray(),
            'theme_updates' => $logs->where('type', 'theme')->values()->toArray(),
            'total_count' => $logs->count(),
            'success_count' => $logs->where('success', true)->count(),
            'failed_count' => $logs->where('success', false)->count(),
        ];
    }

    protected function gatherUptimeData(): array
    {
        $monitor = $this->site->uptimeMonitor;
        if (!$monitor) {
            return ['available' => false];
        }

        $incidents = UptimeIncident::where('monitor_id', $monitor->id)
            ->whereBetween('started_at', [$this->periodStart, $this->periodEnd])
            ->orderBy('started_at', 'desc')
            ->get();

        $totalDowntimeMinutes = $incidents->sum(function ($incident) {
            $end = $incident->resolved_at ?? now();
            return $incident->started_at->diffInMinutes($end);
        });

        // Response time data grouped by date
        $responseTimeData = UptimeCheck::where('monitor_id', $monitor->id)
            ->whereBetween('checked_at', [$this->periodStart, $this->periodEnd])
            ->where('is_up', true)
            ->selectRaw('DATE(checked_at) as date, AVG(response_time) as avg_response_time, MIN(response_time) as min_response_time, MAX(response_time) as max_response_time')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();

        $avgResponseTime = UptimeCheck::where('monitor_id', $monitor->id)
            ->whereBetween('checked_at', [$this->periodStart, $this->periodEnd])
            ->where('is_up', true)
            ->avg('response_time');

        return [
            'available' => true,
            'uptime_percentage' => $monitor->uptime_30d,
            'avg_response_time' => $avgResponseTime ? round($avgResponseTime) : null,
            'incidents' => $incidents->map(fn ($i) => [
                'status' => $i->status,
                'cause' => $i->cause,
                'started_at' => $i->started_at->format('M d, Y H:i'),
                'resolved_at' => $i->resolved_at?->format('M d, Y H:i') ?? 'Ongoing',
                'duration' => $i->duration,
            ])->toArray(),
            'incidents_count' => $incidents->count(),
            'total_downtime_minutes' => $totalDowntimeMinutes,
            'response_time_chart' => $responseTimeData,
        ];
    }

    protected function gatherBackupsData(): array
    {
        $config = $this->site->backupConfig;
        $backups = Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$this->periodStart, $this->periodEnd])
            ->orderBy('created_at', 'desc')
            ->get();

        $lastBackup = Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->orderBy('created_at', 'desc')
            ->first();

        $totalSize = $backups->sum('file_size');

        return [
            'schedule_enabled' => (bool) $config?->is_enabled,
            'frequency' => $config?->frequency ?? 'N/A',
            'type' => $config?->type ?? 'N/A',
            'count' => $backups->count(),
            'total_size' => $this->formatBytes($totalSize),
            'last_backup_at' => $lastBackup?->created_at?->format('M d, Y H:i'),
            'backups' => $backups->map(fn ($b) => [
                'type' => $b->type,
                'created_at' => $b->created_at->format('M d, Y H:i'),
                'file_size' => $b->file_size_formatted,
                'trigger' => $b->trigger,
            ])->toArray(),
        ];
    }

    protected function gatherAnalyticsData(): ?array
    {
        $cache = AnalyticsCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->latest('fetched_at')
            ->first();

        if (!$cache) {
            return null;
        }

        return $cache->data;
    }

    protected function gatherSearchConsoleData(): ?array
    {
        $overview = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->where('data_type', 'overview')
            ->latest('fetched_at')
            ->first();

        $queries = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->where('data_type', 'queries')
            ->latest('fetched_at')
            ->first();

        $pages = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->where('data_type', 'pages')
            ->latest('fetched_at')
            ->first();

        $countries = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->where('data_type', 'countries')
            ->latest('fetched_at')
            ->first();

        $devices = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->where('data_type', 'devices')
            ->latest('fetched_at')
            ->first();

        if (!$overview) {
            return null;
        }

        return [
            'overview' => $overview->data ?? [],
            'queries' => $queries->data ?? [],
            'pages' => $pages->data ?? [],
            'countries' => $countries->data ?? [],
            'devices' => $devices->data ?? [],
        ];
    }

    protected function gatherPerformanceData(): ?array
    {
        $monitor = $this->site->performanceMonitor;
        if (!$monitor) {
            return null;
        }

        $mobileTest = $monitor->latestMobileTest;
        $desktopTest = $monitor->latestDesktopTest;

        if (!$mobileTest && !$desktopTest) {
            return null;
        }

        $data = [
            'mobile_score' => $mobileTest?->performance_score,
            'desktop_score' => $desktopTest?->performance_score,
        ];

        // Mobile vitals
        if ($mobileTest) {
            $data['mobile'] = [
                'performance_score' => $mobileTest->performance_score,
                'accessibility_score' => $mobileTest->accessibility_score,
                'best_practices_score' => $mobileTest->best_practices_score,
                'seo_score' => $mobileTest->seo_score,
                'fcp' => $mobileTest->formatMetric('fcp'),
                'lcp' => $mobileTest->formatMetric('lcp'),
                'cls' => $mobileTest->formatMetric('cls'),
                'tbt' => $mobileTest->formatMetric('tbt'),
                'si' => $mobileTest->formatMetric('si'),
                'fcp_color' => $mobileTest->metricColor('fcp'),
                'lcp_color' => $mobileTest->metricColor('lcp'),
                'cls_color' => $mobileTest->metricColor('cls'),
                'tbt_color' => $mobileTest->metricColor('tbt'),
                'opportunities' => array_slice($mobileTest->opportunities ?? [], 0, 5),
            ];
        }

        // Desktop vitals
        if ($desktopTest) {
            $data['desktop'] = [
                'performance_score' => $desktopTest->performance_score,
                'accessibility_score' => $desktopTest->accessibility_score,
                'best_practices_score' => $desktopTest->best_practices_score,
                'seo_score' => $desktopTest->seo_score,
                'fcp' => $desktopTest->formatMetric('fcp'),
                'lcp' => $desktopTest->formatMetric('lcp'),
                'cls' => $desktopTest->formatMetric('cls'),
                'tbt' => $desktopTest->formatMetric('tbt'),
                'si' => $desktopTest->formatMetric('si'),
                'fcp_color' => $desktopTest->metricColor('fcp'),
                'lcp_color' => $desktopTest->metricColor('lcp'),
                'cls_color' => $desktopTest->metricColor('cls'),
                'tbt_color' => $desktopTest->metricColor('tbt'),
                'opportunities' => array_slice($desktopTest->opportunities ?? [], 0, 5),
            ];
        }

        return $data;
    }

    protected function gatherLinksData(): ?array
    {
        $linkMonitor = $this->site->linkMonitor;
        if (!$linkMonitor) {
            return null;
        }

        $scan = $linkMonitor->latestCompletedScan;
        if (!$scan) {
            return null;
        }

        $brokenLinks = $scan->brokenLinks()
            ->limit(50)
            ->get();

        return [
            'total_links' => $scan->total_links,
            'broken_links' => $scan->broken_links,
            'redirects' => $scan->redirects,
            'pages_scanned' => $scan->pages_scanned,
            'scanned_at' => $scan->completed_at?->format('M d, Y H:i'),
            'broken_links_list' => $brokenLinks->map(fn ($link) => [
                'url' => $link->url,
                'http_code' => $link->http_code,
                'source_url' => $link->source_url,
                'anchor_text' => $link->anchor_text,
            ])->toArray(),
        ];
    }

    protected function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
