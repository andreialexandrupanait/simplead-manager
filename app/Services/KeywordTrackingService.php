<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CrawledPage;
use App\Models\KeywordPageMapping;
use App\Models\KeywordPosition;
use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Models\SiteCrawl;
use App\Models\TrackedKeyword;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class KeywordTrackingService
{
    /**
     * Add a new keyword to track for the given site.
     * The keyword is trimmed and lowercased before persisting.
     */
    public function addKeyword(Site $site, string $keyword): TrackedKeyword
    {
        $normalised = mb_strtolower(trim($keyword));

        return TrackedKeyword::firstOrCreate(
            ['site_id' => $site->id, 'keyword' => $normalised],
        );
    }

    /**
     * Remove a tracked keyword and all its historical position data.
     */
    public function removeKeyword(TrackedKeyword $keyword): void
    {
        // KeywordPosition records are cascade-deleted by the FK constraint,
        // but we delete them explicitly for clarity and immediate effect.
        $keyword->positions()->delete();
        $keyword->delete();
    }

    /**
     * Return all TrackedKeywords for a site, each augmented with its latest
     * KeywordPosition record via a correlated subquery (single DB round-trip).
     *
     * Each item exposes the normal TrackedKeyword attributes plus:
     *   latest_position, latest_clicks, latest_impressions, latest_ctr, latest_date
     */
    public function getKeywordsWithLatestPosition(Site $site): Collection
    {
        $latestPositionSubquery = KeywordPosition::selectRaw('position')
            ->whereColumn('tracked_keyword_id', 'tracked_keywords.id')
            ->orderByDesc('date')
            ->limit(1);

        $latestClicksSubquery = KeywordPosition::selectRaw('clicks')
            ->whereColumn('tracked_keyword_id', 'tracked_keywords.id')
            ->orderByDesc('date')
            ->limit(1);

        $latestImpressionsSubquery = KeywordPosition::selectRaw('impressions')
            ->whereColumn('tracked_keyword_id', 'tracked_keywords.id')
            ->orderByDesc('date')
            ->limit(1);

        $latestCtrSubquery = KeywordPosition::selectRaw('ctr')
            ->whereColumn('tracked_keyword_id', 'tracked_keywords.id')
            ->orderByDesc('date')
            ->limit(1);

        $latestDateSubquery = KeywordPosition::selectRaw('date')
            ->whereColumn('tracked_keyword_id', 'tracked_keywords.id')
            ->orderByDesc('date')
            ->limit(1);

        return TrackedKeyword::where('site_id', $site->id)
            ->addSelect([
                '*',
                'latest_position' => $latestPositionSubquery,
                'latest_clicks' => $latestClicksSubquery,
                'latest_impressions' => $latestImpressionsSubquery,
                'latest_ctr' => $latestCtrSubquery,
                'latest_date' => $latestDateSubquery,
            ])
            ->orderBy('keyword')
            ->get();
    }

    /**
     * Return position history records for a keyword over the last N days,
     * ordered chronologically (oldest first).
     */
    public function getPositionHistory(TrackedKeyword $keyword, int $days = 90): Collection
    {
        return KeywordPosition::where('tracked_keyword_id', $keyword->id)
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date')
            ->get();
    }

    /**
     * Import top Search Console queries as tracked keywords.
     *
     * Reads from the cached `queries` data_type first (no extra API call).
     * Falls back to an empty list when no cache exists, so no hard dependency
     * on a live GSC connection at call time.
     *
     * Returns the number of newly created TrackedKeyword records.
     */
    public function syncFromSearchConsole(Site $site, int $limit = 20): int
    {
        $cache = SearchConsoleCache::where('site_id', $site->id)
            ->where('data_type', 'queries')
            ->where('expires_at', '>', now())
            ->latest('fetched_at')
            ->first();

        if (! $cache) {
            return 0;
        }

        $queries = collect($cache->data)
            ->sortByDesc('clicks')
            ->take($limit);

        $existing = TrackedKeyword::where('site_id', $site->id)
            ->pluck('keyword')
            ->map(fn (string $kw) => mb_strtolower(trim($kw)))
            ->flip();

        $added = 0;

        foreach ($queries as $row) {
            $keyword = mb_strtolower(trim($row['query'] ?? ''));

            if ($keyword === '' || $existing->has($keyword)) {
                continue;
            }

            TrackedKeyword::create([
                'site_id' => $site->id,
                'keyword' => $keyword,
            ]);

            $existing->put($keyword, true);
            $added++;
        }

        return $added;
    }

    /**
     * Detect keyword cannibalization — multiple pages ranking/optimized for the same keyword.
     *
     * Uses keyword_page_mappings (GSC data) first, falls back to crawl data.
     *
     * @return array<string, array{keyword: string, pages: list<array{url: string, where: string}>}>
     */
    public function detectCannibalization(Site $site): array
    {
        $keywords = TrackedKeyword::where('site_id', $site->id)->pluck('keyword', 'id')->all();
        if (empty($keywords)) {
            return [];
        }

        $cannibalized = [];

        // First: check GSC-based keyword-page mappings (most reliable)
        $mappings = KeywordPageMapping::where('site_id', $site->id)
            ->whereIn('tracked_keyword_id', array_keys($keywords))
            ->get()
            ->groupBy('tracked_keyword_id');

        foreach ($mappings as $keywordId => $pageMappings) {
            if ($pageMappings->count() < 2) {
                continue;
            }

            $keyword = $keywords[$keywordId] ?? '';
            $cannibalized[$keyword] = [
                'keyword' => $keyword,
                'pages' => $pageMappings->map(fn ($m) => [
                    'url' => $m->url,
                    'where' => "GSC (pos: {$m->avg_position}, clicks: {$m->clicks})",
                ])->values()->all(),
            ];
        }

        // Second: supplement with crawl-based detection for keywords without GSC mappings
        $latestCrawl = SiteCrawl::where('site_id', $site->id)
            ->where('status', SiteCrawl::STATUS_COMPLETED)
            ->latest()
            ->first();

        if ($latestCrawl) {
            $pages = $latestCrawl->pages()
                ->where('content_type', 'ilike', '%text/html%')
                ->whereBetween('status_code', [200, 299])
                ->get(['url', 'title', 'h1_tags']);

            foreach ($keywords as $id => $keyword) {
                if (isset($cannibalized[$keyword])) {
                    continue;
                }

                $kwLower = mb_strtolower($keyword);
                $matchingPages = [];

                foreach ($pages as $page) {
                    $matches = [];

                    if ($page->title && str_contains(mb_strtolower($page->title), $kwLower)) {
                        $matches[] = 'title';
                    }

                    foreach ($page->h1_tags ?? [] as $h1) {
                        if (str_contains(mb_strtolower($h1), $kwLower)) {
                            $matches[] = 'H1';
                            break;
                        }
                    }

                    if (! empty($matches)) {
                        $matchingPages[] = [
                            'url' => $page->url,
                            'where' => implode(', ', $matches),
                        ];
                    }
                }

                if (count($matchingPages) >= 2) {
                    $cannibalized[$keyword] = [
                        'keyword' => $keyword,
                        'pages' => $matchingPages,
                    ];
                }
            }
        }

        return $cannibalized;
    }

    /**
     * Sync keyword-to-page mappings from Google Search Console.
     *
     * Queries GSC with query+page dimensions and populates keyword_page_mappings
     * for all tracked keywords.
     */
    public function syncKeywordPageMappings(Site $site): int
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
            ->pluck('id', 'keyword')
            ->all();

        if (empty($keywords)) {
            return 0;
        }

        try {
            $service = new GoogleSearchConsoleService($google);
            $propertyUrl = $connection->property_url;

            $startDate = now()->subDays(30)->format('Y-m-d');
            $endDate = now()->subDays(3)->format('Y-m-d');

            $rows = $service->getQueryPagePerformance($propertyUrl, $startDate, $endDate);
        } catch (\Exception $e) {
            Log::warning("Keyword page mapping sync failed for site {$site->id}: {$e->getMessage()}");

            return 0;
        }

        $synced = 0;

        foreach ($rows as $row) {
            $query = mb_strtolower(trim($row['query'] ?? ''));
            $page = $row['page'] ?? '';

            if (! isset($keywords[$query]) || $page === '') {
                continue;
            }

            KeywordPageMapping::updateOrCreate(
                [
                    'tracked_keyword_id' => $keywords[$query],
                    'url' => $page,
                ],
                [
                    'site_id' => $site->id,
                    'source' => 'gsc_auto',
                    'clicks' => $row['clicks'] ?? 0,
                    'impressions' => $row['impressions'] ?? 0,
                    'avg_position' => $row['position'] ?? null,
                    'last_seen_at' => now(),
                ],
            );

            $synced++;
        }

        return $synced;
    }

    /**
     * Get brand vs non-brand keyword statistics.
     */
    public function getBrandVsNonBrand(Site $site): array
    {
        $keywords = $this->getKeywordsWithLatestPosition($site);

        $brand = $keywords->where('is_brand', true);
        $nonBrand = $keywords->where('is_brand', false);

        return [
            'brand' => [
                'count' => $brand->count(),
                'total_clicks' => (int) $brand->sum('latest_clicks'),
                'total_impressions' => (int) $brand->sum('latest_impressions'),
                'avg_position' => $brand->avg('latest_position') ? round($brand->avg('latest_position'), 1) : null,
            ],
            'non_brand' => [
                'count' => $nonBrand->count(),
                'total_clicks' => (int) $nonBrand->sum('latest_clicks'),
                'total_impressions' => (int) $nonBrand->sum('latest_impressions'),
                'avg_position' => $nonBrand->avg('latest_position') ? round($nonBrand->avg('latest_position'), 1) : null,
            ],
        ];
    }

    /**
     * Get keywords grouped by their mapped pages.
     *
     * @return Collection<string, array{url: string, keywords: Collection}>
     */
    public function getKeywordsGroupedByPage(Site $site): Collection
    {
        return KeywordPageMapping::where('site_id', $site->id)
            ->with('trackedKeyword')
            ->orderByDesc('clicks')
            ->get()
            ->groupBy('url')
            ->map(fn (Collection $mappings, string $url) => [
                'url' => $url,
                'total_clicks' => $mappings->sum('clicks'),
                'total_impressions' => $mappings->sum('impressions'),
                'keywords' => $mappings->map(fn (KeywordPageMapping $m) => [
                    'keyword' => $m->trackedKeyword?->keyword ?? '',
                    'clicks' => $m->clicks,
                    'impressions' => $m->impressions,
                    'position' => $m->avg_position,
                ]),
            ])
            ->sortByDesc('total_clicks')
            ->values();
    }

    /**
     * Import keywords manually from an array of strings.
     */
    public function importKeywordsManually(Site $site, array $keywords): int
    {
        $existing = TrackedKeyword::where('site_id', $site->id)
            ->pluck('keyword')
            ->map(fn (string $kw) => mb_strtolower(trim($kw)))
            ->flip();

        $added = 0;

        foreach ($keywords as $keyword) {
            $normalized = mb_strtolower(trim($keyword));

            if ($normalized === '' || $existing->has($normalized)) {
                continue;
            }

            TrackedKeyword::create([
                'site_id' => $site->id,
                'keyword' => $normalized,
            ]);

            $existing->put($normalized, true);
            $added++;
        }

        return $added;
    }

    /**
     * Import keywords from a CSV file.
     *
     * Expected columns: keyword (required), is_brand (optional), landing_page_url (optional)
     */
    public function importKeywordsFromCsv(Site $site, string $filePath): int
    {
        if (! file_exists($filePath)) {
            return 0;
        }

        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return 0;
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);

            return 0;
        }

        $header = array_map(fn ($h) => mb_strtolower(trim($h)), $header);
        $keywordIdx = array_search('keyword', $header);

        if ($keywordIdx === false) {
            fclose($handle);

            return 0;
        }

        $brandIdx = array_search('is_brand', $header);
        $landingIdx = array_search('landing_page_url', $header);

        $existing = TrackedKeyword::where('site_id', $site->id)
            ->pluck('keyword')
            ->map(fn (string $kw) => mb_strtolower(trim($kw)))
            ->flip();

        $added = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $keyword = mb_strtolower(trim($row[$keywordIdx] ?? ''));

            if ($keyword === '' || $existing->has($keyword)) {
                continue;
            }

            $attrs = ['site_id' => $site->id, 'keyword' => $keyword];

            if ($brandIdx !== false && isset($row[$brandIdx])) {
                $attrs['is_brand'] = in_array(mb_strtolower(trim($row[$brandIdx])), ['1', 'true', 'yes'], true);
            }

            if ($landingIdx !== false && isset($row[$landingIdx]) && trim($row[$landingIdx]) !== '') {
                $attrs['landing_page_url'] = trim($row[$landingIdx]);
            }

            TrackedKeyword::create($attrs);
            $existing->put($keyword, true);
            $added++;
        }

        fclose($handle);

        return $added;
    }
}
