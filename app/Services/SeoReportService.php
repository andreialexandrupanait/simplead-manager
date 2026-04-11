<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\BacklinkSnapshot;
use App\Models\PerformanceTest;
use App\Models\SeoAudit;
use App\Models\SeoIssue;
use App\Models\Site;
use Carbon\Carbon;
use Illuminate\Support\Facades\View;

class SeoReportService
{
    public function __construct(
        protected GotenbergService $gotenberg,
    ) {}

    /**
     * Aggregate all SEO data for a report period.
     */
    public function generateReport(Site $site, Carbon $periodStart, Carbon $periodEnd): array
    {
        $latestAudit = SeoAudit::where('site_id', $site->id)
            ->whereBetween('scanned_at', [$periodStart, $periodEnd])
            ->orderByDesc('scanned_at')
            ->first();

        $issues = $latestAudit
            ? SeoIssue::where('seo_audit_id', $latestAudit->id)
                ->orderBySeverity()
                ->get()
            : collect();

        $keywords = app(KeywordTrackingService::class)->getKeywordsWithLatestPosition($site);
        $brandVsNonBrand = app(KeywordTrackingService::class)->getBrandVsNonBrand($site);

        $mobileTest = PerformanceTest::where('site_id', $site->id)
            ->where('status', 'completed')
            ->where('device', 'mobile')
            ->latest('tested_at')
            ->first();

        $backlinkSnapshot = BacklinkSnapshot::where('site_id', $site->id)
            ->latest('date')
            ->first();

        $cannibalization = app(ContentIntelligenceService::class)->detectCannibalization($site);
        $contentGaps = app(ContentIntelligenceService::class)->getContentGaps($site);
        $zeroTrafficPages = app(ContentIntelligenceService::class)->findPagesWithoutTraffic($site);

        return [
            'site' => $site,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'generated_at' => now(),
            'audit' => $latestAudit ? [
                'score' => $latestAudit->score,
                'score_color' => $latestAudit->score_color,
                'score_label' => $latestAudit->score_label,
                'critical_count' => $latestAudit->critical_count,
                'high_count' => $latestAudit->high_count,
                'medium_count' => $latestAudit->medium_count,
                'low_count' => $latestAudit->low_count,
                'total_issues' => $latestAudit->total_issues,
                'scanned_at' => $latestAudit->scanned_at,
            ] : null,
            'issues' => $issues->map(fn (SeoIssue $i) => [
                'title' => $i->title,
                'severity' => $i->severity,
                'category' => $i->category_label,
                'url' => $i->url,
                'recommendation' => $i->recommendation,
            ])->toArray(),
            'keywords' => $keywords->take(20)->map(fn ($kw) => [
                'keyword' => $kw->keyword,
                'position' => $kw->latest_position ? round($kw->latest_position, 1) : null,
                'clicks' => $kw->latest_clicks ?? 0,
                'impressions' => $kw->latest_impressions ?? 0,
                'is_brand' => $kw->is_brand,
            ])->toArray(),
            'brand_vs_non_brand' => $brandVsNonBrand,
            'cwv' => $mobileTest ? [
                'lcp' => $mobileTest->field_lcp ?? $mobileTest->lcp,
                'cls' => $mobileTest->field_cls ?? $mobileTest->cls,
                'inp' => $mobileTest->field_inp,
                'performance_score' => $mobileTest->performance_score,
            ] : null,
            'backlinks' => $backlinkSnapshot ? [
                'total' => $backlinkSnapshot->total_backlinks,
                'referring_domains' => $backlinkSnapshot->referring_domains,
                'new' => $backlinkSnapshot->new_backlinks,
                'lost' => $backlinkSnapshot->lost_backlinks,
            ] : null,
            'cannibalization_count' => count($cannibalization),
            'content_gaps_count' => count($contentGaps),
            'zero_traffic_pages_count' => count($zeroTrafficPages),
        ];
    }

    /**
     * Generate a PDF from the SEO report data.
     */
    public function generatePdf(Site $site, array $data): string
    {
        $html = View::make('reports.seo-standalone', $data)->render();

        return $this->gotenberg->htmlToPdf('', $html);
    }
}
