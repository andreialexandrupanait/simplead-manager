<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class PerformanceGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'performance';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $monitor = $site->performanceMonitor;
        if (! $monitor) {
            return [];
        }

        $mobileTest = $monitor->latestMobileTest;
        $desktopTest = $monitor->latestDesktopTest;

        if (! $mobileTest && ! $desktopTest) {
            return [];
        }

        $cur = $currentSnapshot;
        $prev = $previousSnapshot;

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
}
