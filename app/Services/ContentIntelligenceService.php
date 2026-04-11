<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CrawledPage;
use App\Models\KeywordPageMapping;
use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Models\SiteCrawl;
use Illuminate\Support\Collection;

class ContentIntelligenceService
{
    /**
     * Detect keyword cannibalization using GSC keyword-page mapping data.
     *
     * Returns keywords that rank on multiple pages, indicating cannibalization.
     */
    public function detectCannibalization(Site $site): array
    {
        return app(KeywordTrackingService::class)->detectCannibalization($site);
    }

    /**
     * Find pages that are indexed/crawled but receive zero organic traffic.
     *
     * Cross-references crawled pages against GSC page performance data.
     */
    public function findPagesWithoutTraffic(Site $site, int $daysPeriod = 90): array
    {
        $latestCrawl = SiteCrawl::where('site_id', $site->id)
            ->where('status', SiteCrawl::STATUS_COMPLETED)
            ->latest()
            ->first();

        if (! $latestCrawl) {
            return [];
        }

        // Get all successful HTML pages from the crawl
        $crawledUrls = $latestCrawl->pages()
            ->where('content_type', 'ilike', '%text/html%')
            ->whereBetween('status_code', [200, 299])
            ->pluck('url')
            ->all();

        if (empty($crawledUrls)) {
            return [];
        }

        // Get pages with GSC traffic data
        $pagesWithTraffic = $this->getGscPagesWithClicks($site, $daysPeriod);

        // Also check keyword page mappings
        $pagesFromMappings = KeywordPageMapping::where('site_id', $site->id)
            ->where('clicks', '>', 0)
            ->distinct('url')
            ->pluck('url')
            ->all();

        $allPagesWithTraffic = array_unique(array_merge($pagesWithTraffic, $pagesFromMappings));

        // Normalize URLs for comparison
        $trafficSet = collect($allPagesWithTraffic)
            ->map(fn (string $url) => rtrim($url, '/'))
            ->flip();

        $zeroTrafficPages = [];

        foreach ($crawledUrls as $url) {
            $normalized = rtrim($url, '/');

            if (! $trafficSet->has($normalized)) {
                $zeroTrafficPages[] = $url;
            }
        }

        return $zeroTrafficPages;
    }

    /**
     * Suggest page consolidation based on similar titles and keyword overlap.
     *
     * Groups pages that target similar topics and could be merged.
     */
    public function suggestConsolidation(Site $site): array
    {
        $latestCrawl = SiteCrawl::where('site_id', $site->id)
            ->where('status', SiteCrawl::STATUS_COMPLETED)
            ->latest()
            ->first();

        if (! $latestCrawl) {
            return [];
        }

        $pages = $latestCrawl->pages()
            ->where('content_type', 'ilike', '%text/html%')
            ->whereBetween('status_code', [200, 299])
            ->where('word_count', '>', 0)
            ->get(['url', 'title', 'h1_tags', 'word_count']);

        if ($pages->count() < 2) {
            return [];
        }

        $suggestions = [];

        // Group by similar titles (shared significant words)
        $pageTokens = $pages->map(function (CrawledPage $page) {
            $title = mb_strtolower($page->title ?? '');
            $tokens = $this->extractSignificantTokens($title);

            return [
                'url' => $page->url,
                'title' => $page->title,
                'word_count' => $page->word_count,
                'tokens' => $tokens,
            ];
        })->all();

        $grouped = [];

        for ($i = 0; $i < count($pageTokens); $i++) {
            for ($j = $i + 1; $j < count($pageTokens); $j++) {
                $shared = array_intersect($pageTokens[$i]['tokens'], $pageTokens[$j]['tokens']);

                if (count($shared) >= 2) {
                    $key = implode(' ', array_slice(array_values($shared), 0, 3));

                    if (! isset($grouped[$key])) {
                        $grouped[$key] = [];
                    }

                    $grouped[$key][$pageTokens[$i]['url']] = $pageTokens[$i];
                    $grouped[$key][$pageTokens[$j]['url']] = $pageTokens[$j];
                }
            }
        }

        foreach ($grouped as $topic => $group) {
            if (count($group) < 2) {
                continue;
            }

            $suggestions[] = [
                'topic' => $topic,
                'pages' => array_map(fn ($p) => [
                    'url' => $p['url'],
                    'title' => $p['title'],
                    'word_count' => $p['word_count'],
                ], array_values($group)),
                'reason' => count($group).' pages share similar topic keywords and could be consolidated into a comprehensive single page.',
            ];
        }

        return array_slice($suggestions, 0, 20);
    }

    /**
     * Find content gap opportunities — keywords with impressions but no/low clicks.
     */
    public function getContentGaps(Site $site): array
    {
        $mappings = KeywordPageMapping::where('site_id', $site->id)
            ->where('impressions', '>', 50)
            ->where('clicks', '<', 3)
            ->with('trackedKeyword')
            ->orderByDesc('impressions')
            ->limit(50)
            ->get();

        return $mappings->map(fn (KeywordPageMapping $m) => [
            'keyword' => $m->trackedKeyword?->keyword ?? '',
            'url' => $m->url,
            'impressions' => $m->impressions,
            'clicks' => $m->clicks,
            'position' => $m->avg_position,
            'opportunity' => $m->avg_position !== null && $m->avg_position <= 20
                ? 'High — ranking in top 20 with low CTR, optimize title/meta for clicks'
                : 'Medium — improve ranking to drive more clicks',
        ])->all();
    }

    private function getGscPagesWithClicks(Site $site, int $daysPeriod): array
    {
        $cache = SearchConsoleCache::where('site_id', $site->id)
            ->where('data_type', 'pages')
            ->where('expires_at', '>', now())
            ->latest('fetched_at')
            ->first();

        if (! $cache) {
            return [];
        }

        return collect($cache->data)
            ->filter(fn ($row) => ($row['clicks'] ?? 0) > 0)
            ->pluck('page')
            ->all();
    }

    /**
     * Extract significant tokens from text (filtering stopwords and short words).
     */
    private function extractSignificantTokens(string $text): array
    {
        $stopwords = ['the', 'a', 'an', 'and', 'or', 'but', 'is', 'are', 'was', 'were',
            'in', 'on', 'at', 'to', 'for', 'of', 'with', 'by', 'from', 'as', 'this',
            'that', 'it', 'its', 'be', 'has', 'had', 'have', 'do', 'does', 'did',
            'de', 'si', 'cu', 'la', 'in', 'din', 'pe', 'un', 'o', 'pentru', 'este',
            'care', 'ce', 'sau', '-', '|', '–'];

        $words = preg_split('/[\s\-|–]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(
            $words,
            fn (string $w) => mb_strlen($w) >= 3 && ! in_array($w, $stopwords, true),
        ));
    }
}
