<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class UptimeGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'uptime';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $monitor = $site->uptimeMonitor;
        if (! $monitor) {
            return ['available' => false];
        }

        $incidents = UptimeIncident::where('monitor_id', $monitor->id)
            ->whereBetween('started_at', [$periodStart, $periodEnd])
            ->orderBy('started_at', 'desc')
            ->get();

        $totalDowntimeMinutes = (int) round($incidents->sum(function ($incident) {
            $end = $incident->resolved_at ?? now();

            return $incident->started_at->diffInMinutes($end);
        }));

        $responseTimeData = UptimeCheck::where('monitor_id', $monitor->id)
            ->whereBetween('checked_at', [$periodStart, $periodEnd])
            ->where('is_up', true)
            ->selectRaw('DATE(checked_at) as date, AVG(response_time) as avg_response_time')
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->toArray();

        $avgResponseTime = (float) UptimeCheck::where('monitor_id', $monitor->id)
            ->whereBetween('checked_at', [$periodStart, $periodEnd])
            ->where('is_up', true)
            ->avg('response_time');

        $responseValues = array_column($responseTimeData, 'avg_response_time');
        $chartPoints = $chartService->generateLineChartPoints($responseValues);
        $chartYLabels = ! empty($responseValues)
            ? $chartService->generateYLabels($chartPoints['y_max'], 3, 'ms')
            : [];
        $chartXLabels = $chartService->generateXLabels(array_column($responseTimeData, 'date'));

        $cur = $currentSnapshot;
        $prev = $previousSnapshot;
        $uptimePct = $cur->uptime_percentage ?? $monitor->uptime_30d;

        $downtimeBars = [];
        foreach ($incidents->take(6) as $idx => $inc) {
            $end = $inc->resolved_at ?? now();
            $durMin = $inc->started_at->diffInMinutes($end);
            $downtimeBars[] = [
                'value' => $durMin,
                'label' => '#'.($idx + 1),
                'color' => '#ef4444',
            ];
        }
        $downtimeBarChart = $chartService->generateBarChartData($downtimeBars, 500, 150);

        return [
            'available' => true,
            'uptime_percentage' => $uptimePct,
            'uptime_trend' => $this->calculateTrend($cur->uptime_percentage, $prev?->uptime_percentage),
            'avg_response_time' => $avgResponseTime ? round($avgResponseTime) : null,
            'response_time_trend' => $this->calculateTrendInverse(
                $cur->uptime_avg_response_ms ?? ($avgResponseTime ? round($avgResponseTime) : null),
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
                $cur->uptime_incidents_count ?? $incidents->count(),
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
}
