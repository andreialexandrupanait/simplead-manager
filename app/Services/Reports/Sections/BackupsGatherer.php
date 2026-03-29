<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\Backup;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class BackupsGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'backups';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $config = $site->backupConfig;
        $backups = Backup::where('site_id', $site->id)
            ->whereBetween('created_at', [$periodStart, $periodEnd])
            ->orderBy('created_at', 'desc')
            ->get();

        $successfulCount = $backups->where('status', 'completed')->count();
        $failedCount = $backups->where('status', 'failed')->count();
        $totalSize = $backups->where('status', 'completed')->sum('file_size');

        $cur = $currentSnapshot;
        $prev = $previousSnapshot;

        $donutSegments = [];
        if ($successfulCount > 0) {
            $donutSegments[] = ['value' => $successfulCount, 'label' => __('report.backups_successful', [], $language), 'color' => '#10b981'];
        }
        if ($failedCount > 0) {
            $donutSegments[] = ['value' => $failedCount, 'label' => __('report.backups_failed', [], $language), 'color' => '#ef4444'];
        }
        $donutChart = $chartService->generateDonutData($donutSegments, 120, 18);

        return [
            'schedule_enabled' => (bool) ($config?->is_enabled ?? false),
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
                'status' => $b->status->value ?? $b->status,
                'created_at' => $b->created_at,
                'file_size' => $b->file_size_formatted,
                'trigger' => $b->trigger,
                'destination' => $b->destination ?? 'Local',
            ])->toArray(),
        ];
    }
}
