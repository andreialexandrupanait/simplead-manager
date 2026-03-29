<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class SearchConsoleGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'search_console';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $caches = SearchConsoleCache::where('site_id', $site->id)
            ->where('date_range', '28d')
            ->get()
            ->keyBy('data_type');

        $overviewCache = $caches->get('overview');
        if (! $overviewCache) {
            return [];
        }

        $overviewData = $overviewCache->data ?? [];
        $performanceData = $caches->get('performance_over_time')?->data ?? [];

        $cur = $currentSnapshot;
        $prev = $previousSnapshot;

        $dailyClicks = array_column($performanceData, 'clicks');
        $chartPoints = $chartService->generateLineChartPoints($dailyClicks);
        $chartYLabels = ! empty($dailyClicks)
            ? $chartService->generateYLabels($chartPoints['y_max'], 3)
            : [];
        $chartXLabels = $chartService->generateXLabels(array_column($performanceData, 'date'));

        $dailyImpressions = array_column($performanceData, 'impressions');
        $dualLineChart = $chartService->generateDualLineChartPoints($dailyClicks, $dailyImpressions);
        $dualLineYLabels = ($dualLineChart['y_max'] > 0)
            ? $chartService->generateYLabels($dualLineChart['y_max'], 3)
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
}
