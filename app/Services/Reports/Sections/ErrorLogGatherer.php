<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\PhpErrorLog;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class ErrorLogGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'error_logs';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $baseQuery = fn () => PhpErrorLog::where('site_id', $site->id)
            ->whereBetween('last_seen_at', [$periodStart, $periodEnd]);

        $errors = $baseQuery()->get();

        if ($errors->isEmpty()) {
            return [
                'available' => true,
                'total_count' => 0,
                'fatal_count' => 0,
                'warning_count' => 0,
                'unresolved_count' => 0,
                'top_errors' => [],
            ];
        }

        $totalCount = $errors->count();
        $fatalCount = $errors->where('level', 'fatal')->count();
        $warningCount = $errors->where('level', 'warning')->count();
        $unresolvedCount = $errors->where('is_resolved', false)->count();

        $topErrors = $errors->sortByDesc('count')
            ->take(5)
            ->map(fn (PhpErrorLog $error) => [
                'message' => $error->message,
                'level' => $error->level,
                'count' => $error->count,
                'last_seen_at' => $error->last_seen_at,
            ])
            ->values()
            ->toArray();

        return [
            'available' => true,
            'total_count' => $totalCount,
            'fatal_count' => $fatalCount,
            'warning_count' => $warningCount,
            'unresolved_count' => $unresolvedCount,
            'top_errors' => $topErrors,
        ];
    }
}
