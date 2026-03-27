<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\SecurityRecommendation;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class SecurityChecksGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'security_checks';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $checks = SecurityRecommendation::where('site_id', $site->id)->get();

        if ($checks->isEmpty()) {
            return [];
        }

        $categories = [
            'file_security' => [],
            'login_security' => [],
            'database_security' => [],
            'http_headers' => [],
            'ssl_https' => [],
        ];

        foreach ($checks as $check) {
            $cat = $check->category;
            if (! isset($categories[$cat])) {
                $categories[$cat] = [];
            }
            $categories[$cat][] = [
                'key' => $check->key,
                'title' => $check->title,
                'status' => $check->status,
            ];
        }

        $totalChecks = $checks->count();
        $passed = $checks->where('status', 'passed')->count();
        $failed = $checks->where('status', 'failed')->count();
        $checked = $passed + $failed;
        $score = $checked > 0 ? round(($passed / $checked) * 100) : 0;

        $categorySummary = [];
        foreach ($categories as $cat => $items) {
            $catCollection = collect($items);
            $categorySummary[$cat] = [
                'total' => $catCollection->count(),
                'passed' => $catCollection->where('status', 'passed')->count(),
                'failed' => $catCollection->where('status', 'failed')->count(),
                'checks' => $items,
            ];
        }

        return [
            'overall_score' => $score,
            'total_checks' => $totalChecks,
            'passed' => $passed,
            'failed' => $failed,
            'categories' => $categorySummary,
        ];
    }
}
