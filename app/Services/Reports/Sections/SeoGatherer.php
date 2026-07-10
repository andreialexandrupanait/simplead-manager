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
        $trendChartYLabels = [];
        if ($trendAudits->count() >= 2) {
            $scores = $trendAudits->pluck('score')->map(fn ($s) => (float) $s)->toArray();
            $trendChart = $chartService->generateLineChartPoints($scores, 300, 120);
            $trendChartYLabels = $chartService->generateYLabels((float) $trendChart['y_max'], 3);
        }

        // Pages with status 200 for stats
        $okPages = $audit->pages()->where('status_code', 200);
        $totalOkPages = $okPages->count();

        // Broken links detail (top 10)
        $brokenLinksDetail = $audit->links()->broken()->with('page')
            ->limit(10)->get()
            ->map(fn ($l) => [
                'url' => $l->target_url,
                'status' => $l->status_code,
                'type' => $l->type,
                'found_on' => $l->page?->url,
            ])->toArray();

        // Top pages by most problems
        $topPages = $audit->pages()
            ->where('status_code', 200)
            ->orderByDesc('images_without_alt')
            ->limit(10)
            ->get(['url', 'title_length', 'word_count', 'images_without_alt', 'is_indexable', 'in_sitemap', 'ttfb_seconds'])
            ->map(fn ($p) => [
                'url' => $p->url,
                'title_length' => $p->title_length,
                'word_count' => $p->word_count,
                'images_no_alt' => $p->images_without_alt,
                'indexable' => $p->is_indexable,
                'in_sitemap' => $p->in_sitemap,
                'ttfb_ms' => $p->ttfb_seconds ? round($p->ttfb_seconds * 1000) : null,
            ])->toArray();

        // Structured data coverage
        $pagesWithSchema = $totalOkPages > 0
            ? $audit->pages()->where('status_code', 200)->whereNotNull('structured_data_types')->whereRaw("structured_data_types::text != '[]'")->count()
            : 0;

        // Image stats
        $totalImages = (int) $audit->pages()->where('status_code', 200)->sum('image_count');
        $totalMissingAlt = (int) $audit->pages()->where('status_code', 200)->sum('images_without_alt');

        // Social meta (OG) coverage
        $pagesWithOg = $totalOkPages > 0
            ? $audit->pages()->where('status_code', 200)->whereNotNull('og_tags')->whereRaw("og_tags::text != 'null' AND og_tags::text != '{}'")->count()
            : 0;

        // Internal linking
        $avgInternalLinks = $totalOkPages > 0 ? round((float) $audit->pages()->where('status_code', 200)->avg('internal_link_count'), 1) : 0;
        $orphanCount = $audit->pages()->where('status_code', 200)->where('inbound_internal_links', 0)->where('depth', '>', 0)->count();
        $deepPageCount = $audit->pages()->where('status_code', 200)->where('depth', '>', 3)->count();

        // Robots.txt data
        $robotsData = $audit->robots_txt_data ?? [];

        // Sitemap data
        $sitemapData = $audit->data['sitemap'] ?? [];

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
                'issuer' => $ssl['issuer'] ?? null,
            ],
            'trend_chart' => $trendChart,
            'trend_chart_y_labels' => $trendChartYLabels,
            'previous_score' => $previousAudit?->score,

            // New data
            'security_headers' => $audit->security_headers ?? [],
            'sitemap' => [
                'found' => $sitemapData['found'] ?? false,
                'url' => $sitemapData['url'] ?? null,
                'url_count' => $audit->sitemap_urls_count ?? $sitemapData['url_count'] ?? 0,
            ],
            'robots' => [
                'exists' => $robotsData['exists'] ?? false,
                'allows_crawling' => empty($robotsData['disallow_rules']) || ! in_array('/', $robotsData['disallow_rules'] ?? []),
                'has_sitemap' => ! empty($robotsData['sitemap_urls'] ?? []),
                'disallow_count' => count($robotsData['disallow_rules'] ?? []),
            ],
            'broken_links_detail' => $brokenLinksDetail,
            'top_pages' => $topPages,
            'structured_data' => [
                'pages_with_schema' => $pagesWithSchema,
                'total_pages' => $totalOkPages,
                'coverage_pct' => $totalOkPages > 0 ? round(($pagesWithSchema / $totalOkPages) * 100) : 0,
            ],
            'internal_linking' => [
                'avg_internal_links' => $avgInternalLinks,
                'orphan_count' => $orphanCount,
                'deep_page_count' => $deepPageCount,
            ],
            'images' => [
                'total_images' => $totalImages,
                'total_missing_alt' => $totalMissingAlt,
                'missing_alt_pct' => $totalImages > 0 ? round(($totalMissingAlt / $totalImages) * 100) : 0,
            ],
            'social' => [
                'pages_with_og' => $pagesWithOg,
                'total_pages' => $totalOkPages,
                'coverage_pct' => $totalOkPages > 0 ? round(($pagesWithOg / $totalOkPages) * 100) : 0,
            ],
            'seo_plugin' => $audit->seo_plugin,
        ];
    }
}
