<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\SecurityIssue;
use App\Models\SecurityRecommendation;
use App\Models\SecurityScan;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Models\VulnerabilityAlert;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class SecurityGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'security';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $monitor = $site->securityMonitor;
        if (! $monitor || ! $monitor->is_active) {
            return [];
        }

        $latestScan = SecurityScan::where('site_id', $site->id)
            ->whereBetween('scanned_at', [$periodStart, $periodEnd])
            ->orderByDesc('scanned_at')
            ->first();

        if (! $latestScan) {
            return [];
        }

        $activeIssues = SecurityIssue::where('site_id', $site->id)
            ->active()
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->limit(10)
            ->get();

        $vulnerabilities = VulnerabilityAlert::where('site_id', $site->id)
            ->where('status', 'active')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END")
            ->limit(5)
            ->get();

        $recommendations = SecurityRecommendation::where('site_id', $site->id)
            ->where('status', 'failed')
            ->limit(5)
            ->get();

        $cur = $currentSnapshot;
        $prev = $previousSnapshot;

        return [
            'score' => $latestScan->score,
            'score_trend' => $this->calculateTrend($cur?->security_avg_score, $prev?->security_avg_score),
            'scanned_at' => $latestScan->scanned_at,
            'critical_count' => $latestScan->critical_count,
            'high_count' => $latestScan->high_count,
            'medium_count' => $latestScan->medium_count,
            'low_count' => $latestScan->low_count,
            'total_issues' => $latestScan->total_issues,
            'active_issues' => $activeIssues->map(fn ($i) => [
                'title' => $i->title,
                'severity' => $i->severity,
                'category' => $i->category_label,
                'recommendation' => $i->recommendation,
            ])->toArray(),
            'vulnerabilities' => $vulnerabilities->map(fn ($v) => [
                'title' => $v->title,
                'severity' => $v->severity,
                'software_type' => $v->software_type,
                'software_slug' => $v->software_slug,
                'installed_version' => $v->installed_version,
                'fixed_in_version' => $v->fixed_in_version,
            ])->toArray(),
            'recommendations' => $recommendations->map(fn ($r) => [
                'title' => $r->title,
                'category' => ucfirst(str_replace('_', ' ', $r->category)),
            ])->toArray(),
        ];
    }
}
