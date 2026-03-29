<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\Backup;
use App\Models\DatabaseCleanup;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Models\UpdateLog;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class OverviewGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'overview';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $cur = $currentSnapshot;
        $prev = $previousSnapshot;

        return [
            'updates' => [
                'count' => $cur?->updates_applied ?? $this->countUpdatesInPeriod($site, $periodStart, $periodEnd),
                'trend' => $this->calculateTrend($cur?->updates_applied, $prev?->updates_applied),
            ],
            'uptime' => [
                'percentage' => $cur?->uptime_percentage ?? $site->uptimeMonitor?->uptime_30d,
                'trend' => $this->calculateTrend($cur?->uptime_percentage, $prev?->uptime_percentage),
                'incidents' => $cur?->uptime_incidents_count ?? 0,
            ],
            'backups' => [
                'successful' => $cur?->backups_successful ?? $this->countBackupsInPeriod($site, $periodStart, $periodEnd),
                'total' => $cur?->backups_total ?? $this->countBackupsInPeriod($site, $periodStart, $periodEnd),
                'trend' => $this->calculateTrend($cur?->backups_successful, $prev?->backups_successful),
            ],
            'performance' => [
                'mobile' => $cur?->performance_avg_mobile ?? $site->performanceMonitor?->latest_mobile_score,
                'desktop' => $cur?->performance_avg_desktop ?? $site->performanceMonitor?->latest_desktop_score,
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
                'was_cleaned' => $this->wasDatabaseCleanedInPeriod($site, $periodStart, $periodEnd),
                'space_saved' => $this->getDatabaseSpaceSavedInPeriod($site, $periodStart, $periodEnd),
            ],
            'security' => [
                'score' => $cur?->security_avg_score,
                'trend' => $this->calculateTrend($cur?->security_avg_score, $prev?->security_avg_score),
            ],
        ];
    }

    private function countUpdatesInPeriod(Site $site, Carbon $periodStart, Carbon $periodEnd): int
    {
        return UpdateLog::where('site_id', $site->id)
            ->whereBetween('performed_at', [$periodStart, $periodEnd])
            ->count();
    }

    private function countBackupsInPeriod(Site $site, Carbon $periodStart, Carbon $periodEnd): int
    {
        return Backup::where('site_id', $site->id)
            ->where('status', 'completed')
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->count();
    }

    private function wasDatabaseCleanedInPeriod(Site $site, Carbon $periodStart, Carbon $periodEnd): bool
    {
        return DatabaseCleanup::where('site_id', $site->id)
            ->whereBetween('cleaned_at', [$periodStart, $periodEnd])
            ->exists();
    }

    private function getDatabaseSpaceSavedInPeriod(Site $site, Carbon $periodStart, Carbon $periodEnd): int
    {
        return (int) DatabaseCleanup::where('site_id', $site->id)
            ->whereBetween('cleaned_at', [$periodStart, $periodEnd])
            ->sum('space_saved');
    }
}
