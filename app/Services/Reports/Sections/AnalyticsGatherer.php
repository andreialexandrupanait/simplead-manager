<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\AnalyticsCache;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class AnalyticsGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'analytics';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $cache = $this->resolveCache($site, $periodStart, $periodEnd);

        if (! $cache) {
            return [];
        }

        $raw = $cache->data;
        $overview = $raw['overview'] ?? [];

        $cur = $currentSnapshot;
        $prev = $previousSnapshot;

        $dailyUsers = $raw['users_over_time'] ?? [];
        $userValues = array_column($dailyUsers, 'users');
        $chartPoints = $chartService->generateLineChartPoints($userValues);
        $chartYLabels = ! empty($userValues)
            ? $chartService->generateYLabels($chartPoints['y_max'], 3)
            : [];
        $chartXLabels = $chartService->generateXLabels(array_column($dailyUsers, 'date'));

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
        $trafficBarChart = $chartService->generateBarChartData($trafficBarData, 500, 160, 40, 35);

        $meta = $this->googleDataMeta(
            $cache->start_date,
            $cache->end_date,
            $cache->fetched_at,
            $periodStart,
            $periodEnd,
        );

        return $meta + [
            'total_pageviews' => $overview['pageviews'] ?? 0,
            'total_users' => $overview['total_users'] ?? 0,
            'new_users' => $overview['new_users'] ?? 0,
            'returning_users' => max(0, (int) ($overview['total_users'] ?? 0) - (int) ($overview['new_users'] ?? 0)),
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

    /**
     * P2-03: prefer a cached window that fully covers the report period; only fall
     * back to the rolling 28-day cache when no covering window exists (the fallback
     * is then flagged + labelled by googleDataMeta() rather than shown as current).
     */
    private function resolveCache(Site $site, Carbon $periodStart, Carbon $periodEnd): ?AnalyticsCache
    {
        $covering = AnalyticsCache::where('site_id', $site->id)
            ->whereDate('start_date', '<=', $periodStart->toDateString())
            ->whereDate('end_date', '>=', $periodEnd->toDateString())
            ->orderByDesc('fetched_at')
            ->first();

        if ($covering) {
            return $covering;
        }

        return AnalyticsCache::where('site_id', $site->id)
            ->where('date_range', '28d')
            ->latest('fetched_at')
            ->first();
    }
}
