<?php

declare(strict_types=1);

namespace App\Services\Reports\Sections;

use App\Models\KeywordPosition;
use App\Models\SeoAudit;
use App\Models\SeoIssue;
use App\Models\Site;
use App\Models\SiteMonthlySnapshot;
use App\Models\TrackedKeyword;
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
        $latestAudit = SeoAudit::where('site_id', $site->id)
            ->whereBetween('scanned_at', [$periodStart, $periodEnd])
            ->orderByDesc('scanned_at')
            ->first();

        if (! $latestAudit) {
            return [];
        }

        $topIssues = SeoIssue::where('seo_audit_id', $latestAudit->id)
            ->orderBySeverity()
            ->limit(10)
            ->get();

        $keywords = $this->gatherKeywords($site, $periodEnd);

        $seoPlugin = $this->buildPluginString($latestAudit->seo_plugin, $latestAudit->seo_plugin_version);

        $technical = $this->extractTechnical($latestAudit);

        $previousScore = $previousSnapshot?->seo_score;
        $scoreTrend = ($previousScore !== null)
            ? (float) ($latestAudit->score - $previousScore)
            : null;

        return [
            'score' => $latestAudit->score,
            'score_color' => $latestAudit->score_color,
            'score_label' => $latestAudit->score_label,
            'score_trend' => $this->calculateTrend($currentSnapshot?->seo_score, $previousScore),
            'issues_summary' => [
                'critical_count' => $latestAudit->critical_count,
                'high_count' => $latestAudit->high_count,
                'medium_count' => $latestAudit->medium_count,
                'low_count' => $latestAudit->low_count,
                'info_count' => $latestAudit->info_count,
                'total' => $latestAudit->total_issues,
            ],
            'top_issues' => $topIssues->map(fn (SeoIssue $issue) => [
                'title' => $issue->title,
                'severity' => $issue->severity,
                'category' => $issue->category_label,
                'url' => $issue->url,
            ])->toArray(),
            'seo_plugin' => $seoPlugin,
            'keywords' => $keywords,
            'technical' => $technical,
            'previous_score' => $previousScore !== null ? (int) $previousScore : null,
            'score_diff' => $scoreTrend,
        ];
    }

    // ─── Private Helpers ─────────────────────────────────────────────

    private function gatherKeywords(Site $site, Carbon $periodEnd): array
    {
        $trackedKeywords = TrackedKeyword::where('site_id', $site->id)
            ->with(['positions' => function ($query) use ($periodEnd): void {
                $query->where('date', '<=', $periodEnd->toDateString())
                    ->orderByDesc('date')
                    ->limit(2);
            }])
            ->get();

        if ($trackedKeywords->isEmpty()) {
            return [];
        }

        return $trackedKeywords
            ->map(function (TrackedKeyword $kw): array {
                $positions = $kw->positions->sortByDesc('date')->values();

                /** @var KeywordPosition|null $latest */
                $latest = $positions->first();

                /** @var KeywordPosition|null $prior */
                $prior = $positions->get(1);

                $currentPos = $latest?->position;
                $previousPos = $prior?->position;

                $trend = 'neutral';
                if ($currentPos !== null && $previousPos !== null) {
                    $diff = $previousPos - $currentPos;
                    if ($diff > 0.5) {
                        $trend = 'up';
                    } elseif ($diff < -0.5) {
                        $trend = 'down';
                    }
                }

                return [
                    'keyword' => $kw->keyword,
                    'position' => $currentPos !== null ? round($currentPos, 1) : null,
                    'clicks' => $latest?->clicks ?? 0,
                    'impressions' => $latest?->impressions ?? 0,
                    'trend' => $trend,
                ];
            })
            ->sortBy(fn (array $row) => $row['position'] ?? PHP_INT_MAX)
            ->values()
            ->take(10)
            ->toArray();
    }

    private function buildPluginString(?string $name, ?string $version): ?string
    {
        if ($name === null || $name === '') {
            return null;
        }

        return $version !== null && $version !== ''
            ? "{$name} v{$version}"
            : $name;
    }

    private function extractTechnical(SeoAudit $audit): array
    {
        $data = $audit->data ?? [];

        return [
            'robots_ok' => (bool) ($data['robots_ok'] ?? false),
            'sitemap_ok' => (bool) ($data['sitemap_ok'] ?? false),
            'structured_data_found' => (bool) ($data['structured_data_found'] ?? false),
            'search_visible' => (bool) ($data['search_visible'] ?? true),
        ];
    }
}
