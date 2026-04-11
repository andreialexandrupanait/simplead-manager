<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CompetitorKeywordPosition;
use App\Models\CompetitorSite;
use App\Models\KeywordPosition;
use App\Models\Site;
use App\Models\TrackedKeyword;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CompetitorAnalysisService
{
    /**
     * Add a competitor to track for a site.
     */
    public function addCompetitor(Site $site, string $url, ?string $name = null): CompetitorSite
    {
        $url = rtrim(trim($url), '/');

        return CompetitorSite::firstOrCreate(
            ['site_id' => $site->id, 'competitor_url' => $url],
            ['competitor_name' => $name],
        );
    }

    /**
     * Remove a competitor and all its tracked data.
     */
    public function removeCompetitor(CompetitorSite $competitor): void
    {
        $competitor->keywordPositions()->delete();
        $competitor->delete();
    }

    /**
     * Track keyword positions for a competitor using Google SERP data.
     *
     * This uses the site's GSC connection to query for the competitor's
     * ranking pages in the same keyword space.
     */
    public function trackCompetitorKeywords(Site $site, CompetitorSite $competitor): int
    {
        $connection = $site->searchConsoleConnection;

        if (! $connection || ! $connection->is_active) {
            return 0;
        }

        $google = $connection->googleConnection;

        if (! $google || ! $google->is_active) {
            return 0;
        }

        $keywords = TrackedKeyword::where('site_id', $site->id)
            ->pluck('keyword')
            ->all();

        if (empty($keywords)) {
            return 0;
        }

        try {
            $service = new GoogleSearchConsoleService($google);
            $propertyUrl = $connection->property_url;

            $startDate = now()->subDays(10)->format('Y-m-d');
            $endDate = now()->subDays(3)->format('Y-m-d');

            $tracked = 0;
            $competitorHost = parse_url($competitor->competitor_url, PHP_URL_HOST) ?: '';

            foreach ($keywords as $keyword) {
                // Query GSC for pages ranking for this keyword
                $rows = $service->getFilteredResults(
                    $propertyUrl,
                    $startDate,
                    $endDate,
                    'query',
                    $keyword,
                    'page',
                    50,
                );

                // Look for competitor URLs in the results
                foreach ($rows as $row) {
                    $pageHost = parse_url($row['value'] ?? '', PHP_URL_HOST) ?: '';

                    if ($pageHost === $competitorHost || str_ends_with($pageHost, '.'.$competitorHost)) {
                        CompetitorKeywordPosition::updateOrCreate(
                            [
                                'competitor_site_id' => $competitor->id,
                                'keyword' => $keyword,
                                'date' => now()->subDays(3)->toDateString(),
                            ],
                            [
                                'position' => $row['position'] ?? null,
                                'url' => $row['value'] ?? null,
                            ],
                        );

                        $tracked++;

                        break;
                    }
                }
            }

            return $tracked;

        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Gap analysis: keywords where competitors rank but our site doesn't (or ranks poorly).
     *
     * Returns keywords where at least one competitor has a position but our site
     * either has no position or ranks worse than the competitor.
     */
    public function getGapAnalysis(Site $site): array
    {
        $competitors = CompetitorSite::where('site_id', $site->id)->get();

        if ($competitors->isEmpty()) {
            return [];
        }

        // Get our latest keyword positions
        $ourPositions = $this->getOurLatestPositions($site);

        // Get competitor positions (latest per keyword per competitor)
        $competitorPositions = $this->getCompetitorLatestPositions($competitors->pluck('id')->all());

        $gaps = [];

        foreach ($competitorPositions as $keyword => $compData) {
            $ourPosition = $ourPositions[$keyword] ?? null;

            // Gap: we don't rank or we rank significantly worse
            $bestCompetitorPosition = collect($compData)->min('position');

            if ($ourPosition === null || ($bestCompetitorPosition !== null && $ourPosition > $bestCompetitorPosition + 5)) {
                $gaps[] = [
                    'keyword' => $keyword,
                    'our_position' => $ourPosition,
                    'competitors' => $compData,
                    'best_competitor_position' => $bestCompetitorPosition,
                    'gap' => $ourPosition !== null ? round($ourPosition - $bestCompetitorPosition, 1) : null,
                ];
            }
        }

        // Sort by best competitor position (they rank well, we don't)
        usort($gaps, fn ($a, $b) => ($a['best_competitor_position'] ?? 999) <=> ($b['best_competitor_position'] ?? 999));

        return array_slice($gaps, 0, 50);
    }

    /**
     * Overlap analysis: keywords where both our site and competitors rank.
     *
     * Shows position comparison for shared keyword space.
     */
    public function getOverlapAnalysis(Site $site): array
    {
        $competitors = CompetitorSite::where('site_id', $site->id)->get();

        if ($competitors->isEmpty()) {
            return [];
        }

        $ourPositions = $this->getOurLatestPositions($site);
        $competitorPositions = $this->getCompetitorLatestPositions($competitors->pluck('id')->all());

        $overlaps = [];

        foreach ($ourPositions as $keyword => $ourPosition) {
            if (! isset($competitorPositions[$keyword])) {
                continue;
            }

            $compData = $competitorPositions[$keyword];
            $bestCompetitorPosition = collect($compData)->min('position');

            $overlaps[] = [
                'keyword' => $keyword,
                'our_position' => round($ourPosition, 1),
                'competitors' => $compData,
                'best_competitor_position' => $bestCompetitorPosition !== null ? round($bestCompetitorPosition, 1) : null,
                'advantage' => $bestCompetitorPosition !== null ? round($bestCompetitorPosition - $ourPosition, 1) : null,
            ];
        }

        // Sort: keywords where we're winning first (advantage > 0)
        usort($overlaps, fn ($a, $b) => ($b['advantage'] ?? 0) <=> ($a['advantage'] ?? 0));

        return array_slice($overlaps, 0, 50);
    }

    /**
     * Get a summary comparison across all competitors.
     */
    public function getComparisonSummary(Site $site): array
    {
        $competitors = CompetitorSite::where('site_id', $site->id)->get();

        if ($competitors->isEmpty()) {
            return [];
        }

        $ourPositions = $this->getOurLatestPositions($site);

        $summary = [];

        foreach ($competitors as $competitor) {
            $compPositions = CompetitorKeywordPosition::where('competitor_site_id', $competitor->id)
                ->whereIn('date', function ($q) use ($competitor) {
                    $q->select(DB::raw('MAX(date)'))
                        ->from('competitor_keyword_positions')
                        ->where('competitor_site_id', $competitor->id)
                        ->groupBy('keyword');
                })
                ->get();

            $totalKeywords = $compPositions->count();
            $winning = 0;
            $losing = 0;

            foreach ($compPositions as $cp) {
                $ourPos = $ourPositions[$cp->keyword] ?? null;

                if ($ourPos === null) {
                    $losing++;
                } elseif ($cp->position !== null && $ourPos < $cp->position) {
                    $winning++;
                } elseif ($cp->position !== null && $ourPos > $cp->position) {
                    $losing++;
                }
            }

            $summary[] = [
                'competitor' => $competitor,
                'total_keywords' => $totalKeywords,
                'winning' => $winning,
                'losing' => $losing,
                'tied' => $totalKeywords - $winning - $losing,
                'avg_position' => $compPositions->avg('position') ? round($compPositions->avg('position'), 1) : null,
            ];
        }

        return $summary;
    }

    /**
     * Get our site's latest positions keyed by keyword.
     */
    private function getOurLatestPositions(Site $site): array
    {
        $keywords = TrackedKeyword::where('site_id', $site->id)
            ->with(['positions' => fn ($q) => $q->orderByDesc('date')->limit(1)])
            ->get();

        $positions = [];

        foreach ($keywords as $kw) {
            $latest = $kw->positions->first();
            if ($latest && $latest->position !== null) {
                $positions[$kw->keyword] = $latest->position;
            }
        }

        return $positions;
    }

    /**
     * Get latest competitor positions grouped by keyword.
     */
    private function getCompetitorLatestPositions(array $competitorIds): array
    {
        $positions = CompetitorKeywordPosition::whereIn('competitor_site_id', $competitorIds)
            ->whereIn('id', function ($q) use ($competitorIds) {
                $q->select(DB::raw('MAX(id)'))
                    ->from('competitor_keyword_positions')
                    ->whereIn('competitor_site_id', $competitorIds)
                    ->groupBy('competitor_site_id', 'keyword');
            })
            ->with('competitorSite')
            ->get();

        $grouped = [];

        foreach ($positions as $pos) {
            $keyword = $pos->keyword;

            if (! isset($grouped[$keyword])) {
                $grouped[$keyword] = [];
            }

            $grouped[$keyword][] = [
                'competitor' => $pos->competitorSite?->display_name ?? 'Unknown',
                'competitor_id' => $pos->competitor_site_id,
                'position' => $pos->position !== null ? round($pos->position, 1) : null,
                'url' => $pos->url,
                'date' => $pos->date->format('Y-m-d'),
            ];
        }

        return $grouped;
    }
}
