<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use Carbon\Carbon;

class SeoGatherer extends BaseReportSectionGatherer
{
    protected string $sectionKey = 'seo';

    public function gather(
        Site $site,
        Carbon $periodStart,
        Carbon $periodEnd,
        ?SiteMonthlySnapshot $currentSnapshot,
        ?SiteMonthlySnapshot $previousSnapshot,
        ReportChartService $chartService,
        string $language,
    ): array {
        $audit = $site->seoAudits()->completed()->latest('scanned_at')->first();
        if (! $audit) {
            return [];
        }

        $categories = $audit->category_scores ?? [];
        $previousAudit = $site->seoAudits()
            ->completed()
            ->where('id', '!=', $audit->id)
            ->latest('scanned_at')
            ->first();

        $scoreTrend = $previousAudit
            ? $this->calculateTrend($audit->score, $previousAudit->score)
            : null;

        $topIssues = $audit->issues()
            ->whereIn('severity', ['critical', 'high'])
            ->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 ELSE 3 END")
            ->limit(5)
            ->get(['title', 'severity', 'description', 'recommendation', 'url'])
            ->map(fn ($i) => [
                'title' => $i->title,
                'severity' => $i->severity->value,
                'description' => $i->description,
                'recommendation' => $i->recommendation,
            ])
            ->unique('title')
            ->values()
            ->toArray();

        $brokenLinksCount = $audit->links()->broken()->count();
        $ssl = $audit->ssl_info ?? [];

        // Score trend chart data (last 3 audits)
        $trendAudits = $site->seoAudits()
            ->completed()
            ->latest('scanned_at')
            ->limit(3)
            ->get(['score', 'scanned_at'])
            ->reverse()
            ->values();

        $trendChart = null;
        if ($trendAudits->count() >= 2) {
            $trendChart = $chartService->renderLineChart(
                labels: $trendAudits->pluck('scanned_at')->map(fn ($d) => $d?->format('M d'))->toArray(),
                datasets: [['label' => 'SEO Score', 'data' => $trendAudits->pluck('score')->toArray(), 'color' => '#8D5CF5']],
                width: 300,
                height: 120,
            );
        }

        return [
            'score' => $audit->score,
            'score_trend' => $scoreTrend,
            'scanned_at' => $audit->scanned_at?->format('d/m/Y'),
            'pages_crawled' => $audit->pages_crawled,
            'categories' => [
                'technical' => $categories['technical'] ?? 0,
                'on_page' => $categories['on_page'] ?? 0,
                'performance' => $categories['performance'] ?? 0,
                'other' => $categories['other'] ?? 0,
            ],
            'issues' => [
                'critical' => $audit->critical_count,
                'high' => $audit->high_count,
                'medium' => $audit->medium_count,
                'low' => $audit->low_count,
                'info' => $audit->info_count,
                'total' => $audit->totalIssues(),
            ],
            'top_issues' => $topIssues,
            'broken_links' => $brokenLinksCount,
            'ssl' => [
                'valid' => $ssl['valid'] ?? null,
                'expiry' => $ssl['expiry'] ?? null,
                'days_left' => $ssl['days_until_expiry'] ?? null,
            ],
            'trend_chart' => $trendChart,
            'previous_score' => $previousAudit?->score,
        ];
    }
}
