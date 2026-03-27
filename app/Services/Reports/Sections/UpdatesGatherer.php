<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Models\UpdateLog;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class UpdatesGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'updates';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $logs = UpdateLog::where('site_id', $site->id)
            ->whereBetween('performed_at', [$periodStart, $periodEnd])
            ->orderBy('performed_at', 'desc')
            ->get();

        $pluginCount = $logs->where('type', 'plugin')->count();
        $themeCount = $logs->where('type', 'theme')->count();
        $coreCount = $logs->where('type', 'core')->count();

        $curTotal = $logs->count();
        $prevTotal = $previousSnapshot?->updates_applied;

        $barColors = ['#3b82f6', '#0d9488', '#10b981'];
        $horizontalBarChart = $chartService->generateHorizontalBarData([
            ['value' => $pluginCount, 'label' => __('report.updates_plugins', [], $language), 'color' => $barColors[0]],
            ['value' => $themeCount, 'label' => __('report.updates_themes', [], $language), 'color' => $barColors[1]],
            ['value' => $coreCount, 'label' => __('report.updates_core', [], $language), 'color' => $barColors[2]],
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
            'wp_version' => $site->wp_version,
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

    private function consolidateUpdates(array $allUpdates): array
    {
        $groups = [];

        foreach ($allUpdates as $update) {
            $key = ($update['name'] ?? '').'|'.($update['type'] ?? '');

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

                if ($update['performed_at'] < $groups[$key]['performed_at']) {
                    $groups[$key]['from_version'] = $update['from_version'];
                }

                if ($update['performed_at'] > $groups[$key]['performed_at']) {
                    $groups[$key]['to_version'] = $update['to_version'];
                    $groups[$key]['performed_at'] = $update['performed_at'];
                }

                if (! ($update['success'] ?? true)) {
                    $groups[$key]['success'] = false;
                }
            }
        }

        return array_values($groups);
    }
}
