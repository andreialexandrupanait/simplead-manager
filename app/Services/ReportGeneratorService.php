<?php

namespace App\Services;

use App\Models\AnalyticsCache;
use App\Models\Backup;
use App\Models\DatabaseCleanup;
use App\Models\ReportTemplate;
use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Models\UpdateLog;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Services\ReportRecommendationService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\View;

class ReportGeneratorService
{
    protected array $data = [];
    protected ?SiteMonthlySnapshot $currentSnapshot = null;
    protected ?SiteMonthlySnapshot $previousSnapshot = null;
    protected ReportChartService $chartService;
    protected string $language = 'ro';

    public function __construct(
        protected Site $site,
        protected ReportTemplate $template,
        protected Carbon $periodStart,
        protected Carbon $periodEnd,
    ) {
        $this->chartService = new ReportChartService();
    }

    public function generate(): string
    {
        // Determine language
        $this->language = $this->site->reportConfig?->language ?? 'ro';

        // Load snapshots for comparison
        $this->currentSnapshot = $this->getSnapshot($this->site, $this->periodStart);
        $this->previousSnapshot = $this->getPreviousSnapshot($this->site, $this->periodStart);

        $this->gatherData();

        // Build branding array
        $branding = [
            'company_name' => $this->template->company_name ?? 'SimpleAd',
            'company_logo' => $this->resolveLogoPath($this->template->company_logo_path),
            'company_website' => $this->template->company_website,
            'primary_color' => $this->template->primary_color ?? '#3b82f6',
            'client_name' => $this->site->client?->name ?? $this->site->name,
            'client_logo' => $this->resolveClientLogo(),
        ];

        $sections = $this->template->sections ?? [];

        // Pre-resolve section title/description overrides
        $sectionOverrides = [];
        foreach (['executive_snapshot', 'technical_stability', 'updates', 'backups', 'analytics', 'search_console', 'performance', 'infrastructure', 'recommendations'] as $key) {
            $sectionOverrides[$key] = [
                'title' => $this->template->getSectionTitle($key, $this->language),
                'description' => $this->template->getSectionDescription($key, $this->language),
            ];
        }

        $viewData = [
            'site' => $this->site,
            'data' => $this->data,
            'sections' => $sections,
            'sectionOverrides' => $sectionOverrides,
            'sectionOptions' => $this->template->section_options ?? [],
            'branding' => $branding,
            'language' => $this->language,
            'periodStart' => $this->periodStart,
            'periodEnd' => $this->periodEnd,
            'introText' => $this->template->intro_text ?: __('report.default_intro', [], $this->language),
            'closingText' => $this->template->closing_text ?: __('report.default_closing', [], $this->language),
        ];

        // Prepare logo (white version for dark backgrounds)
        $logoBase64 = $this->getLogoAsBase64();
        $viewData['logoBase64White'] = $logoBase64;

        // Render cover HTML (standalone document, full-bleed, no header/footer)
        $coverHtml = View::make('reports.report-cover', $viewData)->render();

        // Render body HTML (in-flow header bars per section)
        $bodyHtml = View::make('reports.maintenance-report', $viewData)->render();

        // Render closing HTML (standalone document, full-bleed like cover)
        $closingHtml = View::make('reports.report-closing', $viewData)->render();

        // Footer for page numbers only (body pages)
        $footerHtml = View::make('reports.gotenberg-footer', [
            'companyName' => $branding['company_name'],
        ])->render();

        // Generate PDF via Gotenberg (cover + body + closing, then merge)
        $gotenberg = app(GotenbergService::class);
        $pdfBinary = $gotenberg->htmlToPdf($coverHtml, $bodyHtml, $closingHtml, $footerHtml);

        $directory = 'reports/' . $this->site->id;
        $fileName = 'report-' . $this->site->id . '-' . now()->format('Y-m-d-His') . '.pdf';
        $filePath = $directory . '/' . $fileName;

        Storage::disk('local')->makeDirectory($directory);
        Storage::disk('local')->put($filePath, $pdfBinary);

        return $filePath;
    }

    public function getData(): array
    {
        return $this->data;
    }

    // ─── Snapshot Helpers ────────────────────────────────────────────

    protected function getSnapshot(Site $site, Carbon $periodStart): ?SiteMonthlySnapshot
    {
        return SiteMonthlySnapshot::where('site_id', $site->id)
            ->where('year', $periodStart->year)
            ->where('month', $periodStart->month)
            ->first();
    }

    protected function getPreviousSnapshot(Site $site, Carbon $periodStart): ?SiteMonthlySnapshot
    {
        $prev = $periodStart->copy()->subMonth();

        return SiteMonthlySnapshot::where('site_id', $site->id)
            ->where('year', $prev->year)
            ->where('month', $prev->month)
            ->first();
    }

    // ─── Trend Helpers ───────────────────────────────────────────────

    protected function calculateTrend($current, $previous): array
    {
        if ($previous === null || $previous == 0) {
            return [
                'direction' => 'neutral',
                'value' => null,
                'display' => '—',
                'color' => '#6b7280',
            ];
        }

        $change = $current - $previous;
        $percentChange = ($change / abs($previous)) * 100;

        if (abs($percentChange) < 0.5) {
            return [
                'direction' => 'neutral',
                'value' => 0,
                'display' => '— 0%',
                'color' => '#6b7280',
            ];
        }

        $isPositive = $change > 0;

        return [
            'direction' => $isPositive ? 'up' : 'down',
            'value' => round($percentChange, 1),
            'display' => ($isPositive ? '↑' : '↓') . ' ' . abs(round($percentChange, 1)) . '%',
            'color' => $isPositive ? '#10b981' : '#ef4444',
        ];
    }

    /**
     * For metrics where lower is better (response time, bounce rate, avg position).
     */
    protected function calculateTrendInverse($current, $previous): array
    {
        $trend = $this->calculateTrend($current, $previous);

        if ($trend['direction'] === 'up') {
            $trend['color'] = '#ef4444'; // Going up is bad
        } elseif ($trend['direction'] === 'down') {
            $trend['color'] = '#10b981'; // Going down is good
        }

        return $trend;
    }

    // ─── Number Formatting ───────────────────────────────────────────

    protected function formatNumber($value, int $decimals = 0): string
    {
        if ($value === null) {
            return __('report.not_available', [], $this->language);
        }

        $decimalSep = $this->language === 'ro' ? ',' : '.';
        $thousandsSep = $this->language === 'ro' ? '.' : ',';

        return number_format($value, $decimals, $decimalSep, $thousandsSep);
    }

    protected function formatBytes(int $bytes): string
    {
        return \App\Helpers\FormatHelper::bytes($bytes);
    }

    protected function formatDuration(int $minutes): string
    {
        if ($minutes <= 0) {
            return '0 min';
        }

        if ($minutes < 60) {
            return $minutes . ' min';
        }

        $hours = intdiv($minutes, 60);
        $remaining = $minutes % 60;

        return $remaining > 0 ? "{$hours}h {$remaining}min" : "{$hours}h";
    }

    // ─── Logo Resolution ─────────────────────────────────────────────

    protected function resolveLogoPath(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $fullPath = storage_path('app/' . $path);
        if (file_exists($fullPath)) {
            return $fullPath;
        }

        if (file_exists($path)) {
            return $path;
        }

        $publicPath = public_path($path);
        if (file_exists($publicPath)) {
            return $publicPath;
        }

        return null;
    }

    /**
     * Read the company logo and return as a base64 data URI with white fills (for dark header).
     */
    protected function getLogoAsBase64(): ?string
    {
        $settingsService = app(SettingsService::class);
        $logoPath = $settingsService->get('branding.logo');

        if (! $logoPath) {
            return null;
        }

        $fullPath = storage_path('app/public/' . $logoPath);
        if (! file_exists($fullPath)) {
            return null;
        }

        $contents = file_get_contents($fullPath);

        if (str_ends_with(strtolower($fullPath), '.svg')) {
            // Replace all fill colors with white for dark background
            $contents = preg_replace('/fill:\s*#[0-9a-fA-F]{3,6}/', 'fill: #ffffff', $contents);
            $contents = preg_replace('/fill:\s*url\([^)]+\)/', 'fill: #ffffff', $contents);
            $contents = preg_replace('/fill="#[0-9a-fA-F]{3,6}"/', 'fill="#ffffff"', $contents);

            return 'data:image/svg+xml;base64,' . base64_encode($contents);
        }

        $mime = mime_content_type($fullPath) ?: 'image/png';

        return 'data:' . $mime . ';base64,' . base64_encode($contents);
    }

    protected function resolveClientLogo(): ?string
    {
        // Try client logo
        $client = $this->site->client;
        if ($client && $client->logo_path) {
            $resolved = $this->resolveLogoPath($client->logo_path);
            if ($resolved) {
                return $resolved;
            }
        }

        return null;
    }

    // ─── Data Gathering ──────────────────────────────────────────────

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

        if (in_array('database', $sections)) {
            $this->data['database'] = $this->gatherDatabaseData();
        }

        if (in_array('security', $sections)) {
            $this->data['security'] = $this->gatherSecurityData();
        }

        // Always gather SSL, domain, email data (shown inside technical stability)
        $this->data['ssl'] = $this->gatherSslData();
        $this->data['domain'] = $this->gatherDomainData();
        $this->data['email'] = $this->gatherEmailData();

        // Executive snapshot (aggregates from already-gathered sections)
        if (in_array('overview', $sections)) {
            $this->data['executive_snapshot'] = $this->buildExecutiveSnapshot();
        }

        // Recommendations (rule-based, from all gathered data)
        $recService = new ReportRecommendationService($this->data, $this->language);
        $this->data['recommendations'] = $recService->generate();
    }

    protected function gatherOverviewData(): array
    {
        $cur = $this->currentSnapshot;
        $prev = $this->previousSnapshot;

        return [
            'updates' => [
                'count' => $cur?->updates_applied ?? $this->countUpdatesInPeriod(),
                'trend' => $this->calculateTrend($cur?->updates_applied, $prev?->updates_applied),
            ],
            'uptime' => [
                'percentage' => $cur?->uptime_percentage ?? $this->site->uptimeMonitor?->uptime_30d,
                'trend' => $this->calculateTrend($cur?->uptime_percentage, $prev?->uptime_percentage),
                'incidents' => $cur?->uptime_incidents_count ?? 0,
            ],
            'backups' => [
                'successful' => $cur?->backups_successful ?? $this->countBackupsInPeriod(),
                'total' => $cur?->backups_total ?? $this->countBackupsInPeriod(),
                'trend' => $this->calculateTrend($cur?->backups_successful, $prev?->backups_successful),
            ],
            'performance' => [
                'mobile' => $cur?->performance_avg_mobile ?? $this->site->performanceMonitor?->latest_mobile_score,
                'desktop' => $cur?->performance_avg_desktop ?? $this->site->performanceMonitor?->latest_desktop_score,
                'mobile_trend' => $this->calculateTrend($cur?->performance_avg_mobile, $prev?->performance_avg_mobile),
                'desktop_trend' => $this->calculateTrend($cur?->performance_avg_desktop, $prev?->performance_avg_desktop),
            ],
            'analytics' => [
                'pageviews' => $cur?->analytics_pageviews,
                'users' => $cur?->analytics_users,
                'pageviews_trend' => $this->calculateTrend($cur?->analytics_pageviews, $prev?->analytics_pageviews),
                'users_trend' => $this->calculateTrend($cur?->analytics_users, $prev?->analytics_users),
            ],
            'search_console' => [
                'clicks' => $cur?->search_console_clicks,
                'impressions' => $cur?->search_console_impressions,
                'clicks_trend' => $this->calculateTrend($cur?->search_console_clicks, $prev?->search_console_clicks),
                'impressions_trend' => $this->calculateTrend($cur?->search_console_impressions, $prev?->search_console_impressions),
            ],
            'database' => [
                'was_cleaned' => $this->wasDatabaseCleanedInPeriod(),
                'space_saved' => $this->getDatabaseSpaceSavedInPeriod(),
            ],
            'security' => [
                'score' => $cur?->security_avg_score,
                'trend' => $this->calculateTrend($cur?->security_avg_score, $prev?->security_avg_score),
            ],
        ];
    }

    protected function gatherUpdatesData(): array
    {
        $logs = UpdateLog::where('site_id', $this->site->id)
            ->whereBetween('performed_at', [$this->periodStart, $this->periodEnd])
            ->orderBy('performed_at', 'desc')
            ->get();

        $pluginCount = $logs->where('type', 'plugin')->count();
        $themeCount = $logs->where('type', 'theme')->count();
        $coreCount = $logs->where('type', 'core')->count();

        // Trends from snapshot
        $prev = $this->previousSnapshot;
        $curTotal = $logs->count();
        $prevTotal = $prev?->updates_applied;

        // Horizontal bar chart for updates breakdown
        $barColors = ['#3b82f6', '#0d9488', '#10b981'];
        $horizontalBarChart = $this->chartService->generateHorizontalBarData([
            ['value' => $pluginCount, 'label' => __('report.updates_plugins', [], $this->language), 'color' => $barColors[0]],
            ['value' => $themeCount, 'label' => __('report.updates_themes', [], $this->language), 'color' => $barColors[1]],
            ['value' => $coreCount, 'label' => __('report.updates_core', [], $this->language), 'color' => $barColors[2]],
        ]);

        $allUpdates = $logs->map(fn ($l) => [
            'name' => $l->name ?? $l->slug ?? 'WordPress Core',
            'type' => $l->type,
            'performed_at' => $l->performed_at,
            'from_version' => $l->from_version,
            'to_version' => $l->to_version,
            'success' => $l->success,
        ])->toArray();

        $consolidated = $this->consolidateUpdates($allUpdates);

        return [
            'wp_version' => $this->site->wp_version,
            'all_updates' => $allUpdates,
            'consolidated_updates' => $consolidated,
            'core_updates' => $logs->where('type', 'core')->values()->toArray(),
            'plugin_updates' => $logs->where('type', 'plugin')->values()->toArray(),
            'theme_updates' => $logs->where('type', 'theme')->values()->toArray(),
            'total_count' => $curTotal,
            'plugin_count' => $pluginCount,
            'theme_count' => $themeCount,
            'core_count' => $coreCount,
            'success_count' => $logs->where('success', true)->count(),
            'failed_count' => $logs->where('success', false)->count(),
            'total_trend' => $this->calculateTrend($curTotal, $prevTotal),
            'horizontal_bar_chart' => $horizontalBarChart,
        ];
    }

    protected function consolidateUpdates(array $allUpdates): array
    {
        $groups = [];

        foreach ($allUpdates as $update) {
            $key = ($update['name'] ?? '') . '|' . ($update['type'] ?? '');

            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'name' => $update['name'] ?? '—',
                    'type' => $update['type'] ?? '—',
                    'from_version' => $update['from_version'],
                    'to_version' => $update['to_version'],
                    'performed_at' => $update['performed_at'],
                    'success' => $update['success'] ?? true,
                    'update_count' => 1,
                ];
            } else {
                $groups[$key]['update_count']++;

                // Keep earliest from_version
                if ($update['performed_at'] < $groups[$key]['performed_at']) {
                    $groups[$key]['from_version'] = $update['from_version'];
                }

                // Keep latest to_version and performed_at
                if ($update['performed_at'] > $groups[$key]['performed_at']) {
                    $groups[$key]['to_version'] = $update['to_version'];
                    $groups[$key]['performed_at'] = $update['performed_at'];
                }

                // If any failed, mark as failed
                if (! ($update['success'] ?? true)) {
                    $groups[$key]['success'] = false;
                }
            }
        }

        return array_values($groups);
    }

    protected function gatherUptimeData(): array
    {
        $monitor = $this->site->uptimeMonitor;
        if (! $monitor) {
            return ['available' => false];
        }

        $incidents = UptimeIncident::where('monitor_id', $monitor->id)
            ->whereBetween('started_at', [$this->periodStart, $this->periodEnd])
            ->orderBy('started_at', 'desc')
            ->get();

        $totalDowntimeMinutes = (int) round($incidents->sum(function ($incident) {
            $end = $incident->resolved_at ?? now();

            return $incident->started_at->diffInMinutes($end);
        }));

        $responseTimeData = UptimeCheck::where('monitor_id', $monitor->id)
            ->whereBetween('checked_at', [$this->periodStart, $this->periodEnd])
            ->where('is_up', true)
            ->selectRaw('DATE(checked_at) as date, AVG(response_time) as avg_response_time')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();

        $avgResponseTime = UptimeCheck::where('monitor_id', $monitor->id)
            ->whereBetween('checked_at', [$this->periodStart, $this->periodEnd])
            ->where('is_up', true)
            ->avg('response_time');

        // SVG chart data
        $responseValues = array_column($responseTimeData, 'avg_response_time');
        $chartPoints = $this->chartService->generateLineChartPoints($responseValues);
        $chartYLabels = ! empty($responseValues)
            ? $this->chartService->generateYLabels($chartPoints['y_max'], 3, 'ms')
            : [];
        $chartXLabels = $this->chartService->generateXLabels(array_column($responseTimeData, 'date'));

        // Trends from snapshots
        $cur = $this->currentSnapshot;
        $prev = $this->previousSnapshot;
        $uptimePct = $cur?->uptime_percentage ?? $monitor->uptime_30d;

        // Downtime per incident bar chart
        $downtimeBars = [];
        foreach ($incidents->take(6) as $idx => $inc) {
            $end = $inc->resolved_at ?? now();
            $durMin = $inc->started_at->diffInMinutes($end);
            $downtimeBars[] = [
                'value' => $durMin,
                'label' => '#' . ($idx + 1),
                'color' => '#ef4444',
            ];
        }
        $downtimeBarChart = $this->chartService->generateBarChartData($downtimeBars, 500, 150);

        return [
            'available' => true,
            'uptime_percentage' => $uptimePct,
            'uptime_trend' => $this->calculateTrend($cur?->uptime_percentage, $prev?->uptime_percentage),
            'avg_response_time' => $avgResponseTime ? round($avgResponseTime) : null,
            'response_time_trend' => $this->calculateTrendInverse(
                $cur?->uptime_avg_response_ms ?? ($avgResponseTime ? round($avgResponseTime) : null),
                $prev?->uptime_avg_response_ms
            ),
            'incidents' => $incidents->map(fn ($i) => [
                'status' => $i->status,
                'cause' => $i->cause,
                'started_at' => $i->started_at,
                'resolved_at' => $i->resolved_at,
                'duration' => $i->duration,
            ])->toArray(),
            'incidents_count' => $incidents->count(),
            'incidents_trend' => $this->calculateTrendInverse(
                $cur?->uptime_incidents_count ?? $incidents->count(),
                $prev?->uptime_incidents_count
            ),
            'total_downtime_minutes' => $totalDowntimeMinutes,
            'formatted_downtime' => $this->formatDuration($totalDowntimeMinutes),
            'downtime_trend' => $this->calculateTrendInverse($totalDowntimeMinutes, null),
            'response_time_chart' => $responseTimeData,
            'chart_points' => $chartPoints,
            'chart_y_labels' => $chartYLabels,
            'chart_x_labels' => $chartXLabels,
            'downtime_bar_chart' => $downtimeBarChart,
        ];
    }

    protected function gatherBackupsData(): array
    {
        $config = $this->site->backupConfig;
        $backups = Backup::where('site_id', $this->site->id)
            ->whereBetween('created_at', [$this->periodStart, $this->periodEnd])
            ->orderBy('created_at', 'desc')
            ->get();

        $successfulCount = $backups->where('status', 'completed')->count();
        $failedCount = $backups->where('status', 'failed')->count();
        $totalSize = $backups->where('status', 'completed')->sum('file_size');

        // Trends
        $prev = $this->previousSnapshot;
        $cur = $this->currentSnapshot;

        // Donut chart for backup success/failure
        $donutSegments = [];
        if ($successfulCount > 0) {
            $donutSegments[] = ['value' => $successfulCount, 'label' => __('report.backups_successful', [], $this->language), 'color' => '#10b981'];
        }
        if ($failedCount > 0) {
            $donutSegments[] = ['value' => $failedCount, 'label' => __('report.backups_failed', [], $this->language), 'color' => '#ef4444'];
        }
        $donutChart = $this->chartService->generateDonutData($donutSegments, 120, 18);

        return [
            'schedule_enabled' => (bool) $config?->is_enabled,
            'frequency' => $config?->frequency ?? 'N/A',
            'type' => $config?->type ?? 'N/A',
            'count' => $successfulCount,
            'failed_count' => $failedCount,
            'total_size' => $this->formatBytes($totalSize),
            'successful_trend' => $this->calculateTrend($cur?->backups_successful ?? $successfulCount, $prev?->backups_successful),
            'failed_trend' => $this->calculateTrendInverse($cur?->backups_failed ?? $failedCount, $prev?->backups_failed),
            'donut_chart' => $donutChart,
            'backups' => $backups->map(fn ($b) => [
                'type' => $b->type,
                'status' => $b->status,
                'created_at' => $b->created_at,
                'file_size' => $b->file_size_formatted,
                'trigger' => $b->trigger,
                'destination' => $b->destination ?? 'Local',
            ])->toArray(),
        ];
    }

    protected function gatherAnalyticsData(): ?array
    {
        $cache = AnalyticsCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->latest('fetched_at')
            ->first();

        if (! $cache) {
            return null;
        }

        $raw = $cache->data;
        $overview = $raw['overview'] ?? [];

        // Trends
        $cur = $this->currentSnapshot;
        $prev = $this->previousSnapshot;

        // SVG chart for daily users
        $dailyUsers = $raw['users_over_time'] ?? [];
        $userValues = array_column($dailyUsers, 'users');
        $chartPoints = $this->chartService->generateLineChartPoints($userValues);
        $chartYLabels = ! empty($userValues)
            ? $this->chartService->generateYLabels($chartPoints['y_max'], 3)
            : [];
        $chartXLabels = $this->chartService->generateXLabels(array_column($dailyUsers, 'date'));

        // Traffic by channel bar chart (top 5)
        $trafficColors = ['#2563eb', '#0d9488', '#10b981', '#f59e0b', '#ef4444'];
        $topSources = collect($raw['traffic_sources'] ?? [])->take(5);
        $trafficBarData = [];
        foreach ($topSources->values() as $idx => $s) {
            $trafficBarData[] = [
                'value' => $s['users'] ?? $s['sessions'] ?? 0,
                'label' => $s['channel'] ?? ($s['source'] ?? '—'),
                'color' => $trafficColors[$idx] ?? '#3b82f6',
            ];
        }
        $trafficBarChart = $this->chartService->generateBarChartData($trafficBarData, 500, 160, 40, 35);

        return [
            'total_pageviews' => $overview['pageviews'] ?? 0,
            'total_users' => $overview['total_users'] ?? 0,
            'new_users' => $overview['new_users'] ?? 0,
            'returning_users' => max(0, ($overview['total_users'] ?? 0) - ($overview['new_users'] ?? 0)),
            'bounce_rate' => $overview['bounce_rate'] ?? 0,
            'avg_session_duration' => $overview['avg_session_duration'] ?? 0,
            'engagement_rate' => $overview['engagement_rate'] ?? 0,
            'sessions' => $overview['sessions'] ?? 0,
            'daily_users' => $dailyUsers,
            'chart_points' => $chartPoints,
            'chart_y_labels' => $chartYLabels,
            'chart_x_labels' => $chartXLabels,
            'pageviews_trend' => $this->calculateTrend($cur?->analytics_pageviews, $prev?->analytics_pageviews),
            'users_trend' => $this->calculateTrend($cur?->analytics_users, $prev?->analytics_users),
            'bounce_rate_trend' => $this->calculateTrendInverse($overview['bounce_rate'] ?? null, null),
            'duration_trend' => $this->calculateTrend($overview['avg_session_duration'] ?? null, null),
            'traffic_bar_chart' => $trafficBarChart,
            'traffic_sources' => collect($raw['traffic_sources'] ?? [])->map(fn ($s) => [
                'source' => $s['channel'] ?? ($s['source'] ?? '—'),
                'users' => $s['users'] ?? $s['sessions'] ?? 0,
                'sessions' => $s['sessions'] ?? 0,
            ])->toArray(),
            'top_pages' => collect($raw['top_pages'] ?? [])->reject(function ($page) {
                $path = $page['page'] ?? $page['path'] ?? '';
                return preg_match('#/wp-(login|admin|cron|json)|/xmlrpc\.php#', $path);
            })->reject(function ($page) {
                return ($page['pageviews'] ?? $page['views'] ?? 0) == 0;
            })->values()->take(10)->toArray(),
            'devices' => collect($raw['devices'] ?? [])->map(fn ($d) => [
                'device' => $d['device'] ?? '—',
                'users' => $d['sessions'] ?? $d['users'] ?? 0,
            ])->toArray(),
            'countries' => collect($raw['countries'] ?? [])->take(5)->toArray(),
            'cities' => [],
        ];
    }

    protected function gatherSearchConsoleData(): ?array
    {
        $caches = SearchConsoleCache::where('site_id', $this->site->id)
            ->where('date_range', '28d')
            ->get()
            ->keyBy('data_type');

        $overviewCache = $caches->get('overview');
        if (! $overviewCache) {
            return null;
        }

        $overviewData = $overviewCache->data ?? [];
        $performanceData = $caches->get('performance_over_time')?->data ?? [];

        // Trends
        $cur = $this->currentSnapshot;
        $prev = $this->previousSnapshot;

        // SVG chart for daily clicks
        $dailyClicks = array_column($performanceData, 'clicks');
        $chartPoints = $this->chartService->generateLineChartPoints($dailyClicks);
        $chartYLabels = ! empty($dailyClicks)
            ? $this->chartService->generateYLabels($chartPoints['y_max'], 3)
            : [];
        $chartXLabels = $this->chartService->generateXLabels(array_column($performanceData, 'date'));

        // Dual line chart (clicks + impressions)
        $dailyImpressions = array_column($performanceData, 'impressions');
        $dualLineChart = $this->chartService->generateDualLineChartPoints($dailyClicks, $dailyImpressions);
        $dualLineYLabels = ($dualLineChart['y_max'] > 0)
            ? $this->chartService->generateYLabels($dualLineChart['y_max'], 3)
            : [];

        $mappedOverview = [
            'total_clicks' => $overviewData['clicks'] ?? 0,
            'total_impressions' => $overviewData['impressions'] ?? 0,
            'avg_ctr' => ($overviewData['ctr'] ?? 0) / 100,
            'avg_position' => $overviewData['position'] ?? 0,
            'daily_data' => $performanceData,
            'chart_points' => $chartPoints,
            'chart_y_labels' => $chartYLabels,
            'chart_x_labels' => $chartXLabels,
        ];

        // Trends
        $mappedOverview['clicks_trend'] = $this->calculateTrend($cur?->search_console_clicks, $prev?->search_console_clicks);
        $mappedOverview['impressions_trend'] = $this->calculateTrend($cur?->search_console_impressions, $prev?->search_console_impressions);
        $mappedOverview['ctr_trend'] = $this->calculateTrend(($overviewData['ctr'] ?? 0), null);
        $mappedOverview['position_trend'] = $this->calculateTrendInverse($cur?->search_console_avg_position, $prev?->search_console_avg_position);

        $queries = collect($caches->get('queries')?->data ?? [])->map(fn ($q) => array_merge($q, [
            'ctr' => ($q['ctr'] ?? 0) / 100,
        ]))->take(10)->toArray();

        $pages = collect($caches->get('pages')?->data ?? [])->map(fn ($p) => array_merge($p, [
            'ctr' => ($p['ctr'] ?? 0) / 100,
        ]))->take(10)->toArray();

        return [
            'overview' => $mappedOverview,
            'queries' => $queries,
            'pages' => $pages,
            'countries' => $caches->get('countries')?->data ?? [],
            'devices' => $caches->get('devices')?->data ?? [],
            'dual_line_chart' => $dualLineChart,
            'dual_line_y_labels' => $dualLineYLabels,
            'dual_line_x_labels' => $chartXLabels,
        ];
    }

    protected function gatherPerformanceData(): ?array
    {
        $monitor = $this->site->performanceMonitor;
        if (! $monitor) {
            return null;
        }

        $mobileTest = $monitor->latestMobileTest;
        $desktopTest = $monitor->latestDesktopTest;

        if (! $mobileTest && ! $desktopTest) {
            return null;
        }

        // Trends
        $cur = $this->currentSnapshot;
        $prev = $this->previousSnapshot;

        $data = [
            'mobile_score' => $mobileTest?->performance_score,
            'desktop_score' => $desktopTest?->performance_score,
            'mobile_trend' => $this->calculateTrend($cur?->performance_avg_mobile, $prev?->performance_avg_mobile),
            'desktop_trend' => $this->calculateTrend($cur?->performance_avg_desktop, $prev?->performance_avg_desktop),
        ];

        if ($mobileTest) {
            $data['mobile'] = [
                'performance_score' => $mobileTest->performance_score,
                'tested_at' => $mobileTest->created_at,
                'fcp' => $mobileTest->formatMetric('fcp'),
                'lcp' => $mobileTest->formatMetric('lcp'),
                'cls' => $mobileTest->formatMetric('cls'),
                'tbt' => $mobileTest->formatMetric('tbt'),
                'si' => $mobileTest->formatMetric('si'),
                'fcp_color' => $mobileTest->metricColor('fcp'),
                'lcp_color' => $mobileTest->metricColor('lcp'),
                'cls_color' => $mobileTest->metricColor('cls'),
                'tbt_color' => $mobileTest->metricColor('tbt'),
                'si_color' => $mobileTest->metricColor('si'),
            ];
        }

        if ($desktopTest) {
            $data['desktop'] = [
                'performance_score' => $desktopTest->performance_score,
                'tested_at' => $desktopTest->created_at,
                'fcp' => $desktopTest->formatMetric('fcp'),
                'lcp' => $desktopTest->formatMetric('lcp'),
                'cls' => $desktopTest->formatMetric('cls'),
                'tbt' => $desktopTest->formatMetric('tbt'),
                'si' => $desktopTest->formatMetric('si'),
                'fcp_color' => $desktopTest->metricColor('fcp'),
                'lcp_color' => $desktopTest->metricColor('lcp'),
                'cls_color' => $desktopTest->metricColor('cls'),
                'tbt_color' => $desktopTest->metricColor('tbt'),
                'si_color' => $desktopTest->metricColor('si'),
            ];
        }

        return $data;
    }

    protected function gatherDatabaseData(): ?array
    {
        $cleanups = DatabaseCleanup::where('site_id', $this->site->id)
            ->whereBetween('cleaned_at', [$this->periodStart, $this->periodEnd])
            ->get();

        if ($cleanups->isEmpty()) {
            return null;
        }

        $latestCleanup = $cleanups->sortByDesc('cleaned_at')->first();

        return [
            'total_saved' => $cleanups->sum('space_saved'),
            'last_cleanup_date' => $latestCleanup->cleaned_at,
            'categories' => [
                ['key' => 'revisions', 'deleted' => $cleanups->sum('revisions_deleted'), 'saved' => $cleanups->sum('revisions_saved')],
                ['key' => 'auto_drafts', 'deleted' => $cleanups->sum('auto_drafts_deleted'), 'saved' => $cleanups->sum('auto_drafts_saved')],
                ['key' => 'trashed', 'deleted' => $cleanups->sum('trash_posts_deleted'), 'saved' => $cleanups->sum('trash_posts_saved')],
                ['key' => 'spam', 'deleted' => $cleanups->sum('spam_comments_deleted'), 'saved' => $cleanups->sum('spam_comments_saved')],
                ['key' => 'trash_comments', 'deleted' => $cleanups->sum('trash_comments_deleted'), 'saved' => $cleanups->sum('trash_comments_saved')],
                ['key' => 'transients', 'deleted' => $cleanups->sum('transients_deleted'), 'saved' => $cleanups->sum('transients_saved')],
                ['key' => 'orphaned', 'deleted' => $cleanups->sum('orphaned_meta_deleted'), 'saved' => $cleanups->sum('orphaned_saved')],
            ],
        ];
    }

    protected function gatherSecurityData(): ?array
    {
        $monitor = $this->site->securityMonitor;
        if (! $monitor || ! $monitor->is_active) {
            return null;
        }

        // Get the latest scan within the reporting period
        $latestScan = \App\Models\SecurityScan::where('site_id', $this->site->id)
            ->whereBetween('scanned_at', [$this->periodStart, $this->periodEnd])
            ->orderByDesc('scanned_at')
            ->first();

        if (! $latestScan) {
            return null;
        }

        // Get active issues
        $activeIssues = \App\Models\SecurityIssue::where('site_id', $this->site->id)
            ->active()
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->limit(10)
            ->get();

        // Get vulnerability alerts
        $vulnerabilities = \App\Models\VulnerabilityAlert::where('site_id', $this->site->id)
            ->where('status', 'active')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->limit(5)
            ->get();

        // Get recommendations
        $recommendations = \App\Models\SecurityRecommendation::where('site_id', $this->site->id)
            ->where('status', 'failed')
            ->limit(5)
            ->get();

        // Trend from snapshots
        $cur = $this->currentSnapshot;
        $prev = $this->previousSnapshot;

        return [
            'score' => $latestScan->score,
            'score_trend' => $this->calculateTrend($cur?->security_avg_score, $prev?->security_avg_score),
            'scanned_at' => $latestScan->scanned_at,
            'critical_count' => $latestScan->critical_count,
            'high_count' => $latestScan->high_count,
            'medium_count' => $latestScan->medium_count,
            'low_count' => $latestScan->low_count,
            'total_issues' => $latestScan->total_issues,
            'active_issues' => $activeIssues->map(fn ($i) => [
                'title' => $i->title,
                'severity' => $i->severity,
                'category' => $i->category_label,
                'recommendation' => $i->recommendation,
            ])->toArray(),
            'vulnerabilities' => $vulnerabilities->map(fn ($v) => [
                'title' => $v->title,
                'severity' => $v->severity,
                'software_type' => $v->software_type,
                'software_slug' => $v->software_slug,
                'installed_version' => $v->installed_version,
                'fixed_in_version' => $v->fixed_in_version,
            ])->toArray(),
            'recommendations' => $recommendations->map(fn ($r) => [
                'title' => $r->title,
                'category' => ucfirst(str_replace('_', ' ', $r->category)),
            ])->toArray(),
        ];
    }

    // ─── SSL / Domain / Email ───────────────────────────────────────

    protected function gatherSslData(): ?array
    {
        $ssl = $this->site->sslCertificate;
        if (! $ssl || $ssl->status === 'pending') {
            return null;
        }

        return [
            'status' => $ssl->status,
            'status_label' => $ssl->status_label,
            'issuer' => $ssl->issuer,
            'expires_at' => $ssl->expires_at?->format('d/m/Y'),
            'days_remaining' => $ssl->days_remaining,
            'protocol' => $ssl->protocol,
            'key_size' => $ssl->key_size,
        ];
    }

    protected function gatherDomainData(): ?array
    {
        $domain = $this->site->domainMonitor;
        if (! $domain || $domain->status === 'pending') {
            return null;
        }

        return [
            'registrar' => $domain->registrar,
            'expires_at' => $domain->expires_at?->format('d/m/Y'),
            'days_remaining' => $domain->days_remaining,
            'dns_provider' => $domain->dns_provider,
            'status' => $domain->status,
            'status_label' => $domain->status_label,
        ];
    }

    protected function gatherEmailData(): ?array
    {
        $email = $this->site->latestEmailHealthCheck;
        if (! $email) {
            return null;
        }

        return [
            'score' => $email->score,
            'status' => $email->status,
            'spf_exists' => $email->spf_exists,
            'spf_status' => $email->spf_status,
            'dkim_exists' => $email->dkim_exists,
            'dkim_status' => $email->dkim_status,
            'dmarc_exists' => $email->dmarc_exists,
            'dmarc_policy' => $email->dmarc_policy,
            'checked_at' => $email->checked_at?->format('d/m/Y'),
        ];
    }

    // ─── Executive Snapshot ─────────────────────────────────────────

    protected function buildExecutiveSnapshot(): array
    {
        $uptime = $this->data['uptime'] ?? [];
        $updates = $this->data['updates'] ?? [];
        $backups = $this->data['backups'] ?? [];
        $perf = $this->data['performance'] ?? [];
        $analytics = $this->data['analytics'] ?? [];
        $sc = $this->data['search_console'] ?? [];

        $uptimePct = $uptime['uptime_percentage'] ?? null;
        $downtimeMin = $uptime['total_downtime_minutes'] ?? null;
        $incidentsCount = $uptime['incidents_count'] ?? 0;
        $pluginUpdates = $updates['total_count'] ?? 0;
        $backupCount = $backups['count'] ?? 0;
        $backupTotal = ($backups['count'] ?? 0) + ($backups['failed_count'] ?? 0);
        $desktopScore = $perf['desktop_score'] ?? null;
        $mobileScore = $perf['mobile_score'] ?? null;
        $totalUsers = $analytics['total_users'] ?? null;
        $impressions = $sc['overview']['total_impressions'] ?? null;

        return [
            [
                'key' => 'uptime',
                'value' => $uptimePct !== null ? $this->formatNumber($uptimePct, 2) . '%' : __('report.snapshot_no_data', [], $this->language),
                'label' => __('report.snapshot_uptime', [], $this->language),
                'note' => $incidentsCount > 0 ? __('report.snapshot_incidents', ['count' => $incidentsCount], $this->language) : null,
                'status' => $this->getUptimeStatus($uptimePct),
            ],
            [
                'key' => 'downtime',
                'value' => $this->formatDuration($downtimeMin ?? 0),
                'label' => __('report.snapshot_downtime', [], $this->language),
                'note' => null,
                'status' => $downtimeMin > 0 ? 'warning' : 'good',
            ],
            [
                'key' => 'updates',
                'value' => (string) $pluginUpdates,
                'label' => __('report.snapshot_updates', [], $this->language),
                'note' => null,
                'status' => 'neutral',
            ],
            [
                'key' => 'backups',
                'value' => (string) $backupCount,
                'label' => __('report.snapshot_backups', [], $this->language),
                'note' => $backupTotal > 0 ? __('report.snapshot_of_total', ['total' => $backupTotal], $this->language) : null,
                'status' => ($backups['failed_count'] ?? 0) > 0 ? 'danger' : 'good',
            ],
            [
                'key' => 'desktop_perf',
                'value' => $desktopScore !== null ? (string) $desktopScore : __('report.snapshot_no_data', [], $this->language),
                'label' => __('report.snapshot_desktop_perf', [], $this->language),
                'note' => null,
                'status' => $this->getScoreStatus($desktopScore),
            ],
            [
                'key' => 'mobile_perf',
                'value' => $mobileScore !== null ? (string) $mobileScore : __('report.snapshot_no_data', [], $this->language),
                'label' => __('report.snapshot_mobile_perf', [], $this->language),
                'note' => null,
                'status' => $this->getScoreStatus($mobileScore),
            ],
            [
                'key' => 'users',
                'value' => $totalUsers !== null ? $this->formatNumber($totalUsers) : __('report.snapshot_no_data', [], $this->language),
                'label' => __('report.snapshot_users', [], $this->language),
                'note' => null,
                'status' => 'neutral',
            ],
            [
                'key' => 'impressions',
                'value' => $impressions !== null ? $this->formatNumber($impressions) : __('report.snapshot_no_data', [], $this->language),
                'label' => __('report.snapshot_impressions', [], $this->language),
                'note' => null,
                'status' => 'neutral',
            ],
        ];
    }

    protected function getUptimeStatus(?float $pct): string
    {
        if ($pct === null) {
            return 'neutral';
        }

        return $pct >= 99.5 ? 'good' : ($pct >= 95 ? 'warning' : 'danger');
    }

    protected function getScoreStatus(?float $score): string
    {
        if ($score === null) {
            return 'neutral';
        }

        return $score >= 90 ? 'good' : ($score >= 50 ? 'warning' : 'danger');
    }

    // ─── Quick Helpers ───────────────────────────────────────────────

    protected function countUpdatesInPeriod(): int
    {
        return UpdateLog::where('site_id', $this->site->id)
            ->whereBetween('performed_at', [$this->periodStart, $this->periodEnd])
            ->count();
    }

    protected function countBackupsInPeriod(): int
    {
        return Backup::where('site_id', $this->site->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$this->periodStart, $this->periodEnd])
            ->count();
    }

    protected function wasDatabaseCleanedInPeriod(): bool
    {
        return DatabaseCleanup::where('site_id', $this->site->id)
            ->whereBetween('cleaned_at', [$this->periodStart, $this->periodEnd])
            ->exists();
    }

    protected function getDatabaseSpaceSavedInPeriod(): int
    {
        return (int) DatabaseCleanup::where('site_id', $this->site->id)
            ->whereBetween('cleaned_at', [$this->periodStart, $this->periodEnd])
            ->sum('space_saved');
    }
}
