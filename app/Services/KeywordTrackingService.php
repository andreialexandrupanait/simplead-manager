<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\KeywordPosition;
use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Models\TrackedKeyword;
use Illuminate\Support\Collection;

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
}
