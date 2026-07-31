<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\SafeUpdate;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Models\UpdateLog;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;

class UpdatesGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'updates';

    /** Two pairs is a page of the report; more of them is an album, not a report. */
    private const MAX_VISUAL_CHECKS = 2;

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
            'visual_checks' => $this->visualChecks($site, $periodStart, $periodEnd),
        ];
    }

    /**
     * Before/after screenshot pairs from the period's safe updates (SPEC §7.4 etapa 2:
     * "stocate pentru verificare manuală și pentru raport").
     *
     * The pair is shown so a human can judge it. The recorded pixel difference is
     * deliberately NOT shown as a verdict — §7.4 rules out pixel diffing as a gate,
     * so presenting it as pass/fail in a client-facing report would be a claim the
     * measurement cannot support.
     *
     * Images are inlined as data URIs because the PDF is rendered by Chromium in a
     * separate container, which cannot reach this app's public disk by path.
     *
     * @return array<int, array{name: string, performed_at: mixed, before: string, after: string}>
     */
    private function visualChecks(Site $site, Carbon $periodStart, Carbon $periodEnd): array
    {
        $updates = SafeUpdate::where('site_id', $site->id)
            ->whereBetween('completed_at', [$periodStart, $periodEnd])
            ->whereNotNull('screenshot_before_path')
            ->whereNotNull('screenshot_after_path')
            ->orderByDesc('completed_at')
            ->limit(self::MAX_VISUAL_CHECKS)
            ->get();

        $pairs = [];

        foreach ($updates as $update) {
            $before = $this->inlineImage($update->screenshot_before_path);
            $after = $this->inlineImage($update->screenshot_after_path);

            // A half-pair says nothing; retention may already have removed the files.
            if ($before === null || $after === null) {
                continue;
            }

            $pairs[] = [
                'name' => $update->name ?? $update->slug ?? 'WordPress Core',
                'performed_at' => $update->completed_at,
                'before' => $before,
                'after' => $after,
            ];
        }

        return $pairs;
    }

    private function inlineImage(string $path): ?string
    {
        $disk = Storage::disk('public');

        if (! $disk->exists($path)) {
            return null;
        }

        $binary = $disk->get($path);

        if ($binary === null || $binary === '') {
            return null;
        }

        return 'data:image/jpeg;base64,'.base64_encode($binary);
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
