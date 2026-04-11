<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\Site;
use App\Models\SiteContent;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class ContentFreshnessGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'content_freshness';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $content = SiteContent::where('site_id', $site->id)
            ->published()
            ->get();

        if ($content->isEmpty()) {
            return ['available' => false];
        }

        $totalContent = $content->count();

        $staleCount = $content->where('days_since_modified', '>', 180)->count();
        $agingCount = $content->filter(
            fn (SiteContent $item) => $item->days_since_modified > 90 && $item->days_since_modified <= 180
        )->count();
        $freshCount = $content->where('days_since_modified', '<=', 90)->count();

        $oldestItem = $content->sortByDesc('days_since_modified')->first();
        $oldestContent = $oldestItem ? [
            'title' => $oldestItem->title,
            'days_since_modified' => $oldestItem->days_since_modified,
            'type' => $oldestItem->type,
        ] : null;

        $avgAgeDays = $content->isNotEmpty()
            ? (int) round($content->avg('days_since_modified'))
            : 0;

        return [
            'available' => true,
            'total_content' => $totalContent,
            'stale_count' => $staleCount,
            'aging_count' => $agingCount,
            'fresh_count' => $freshCount,
            'oldest_content' => $oldestContent,
            'avg_age_days' => $avgAgeDays,
        ];
    }
}
