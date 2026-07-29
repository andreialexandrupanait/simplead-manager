<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SearchConsoleCache;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * SPEC §7.5 — derive a site's "key URLs": the homepage plus the top pages by
 * Search-Console clicks. These feed the update smoke check (§7.4) and the
 * report. Recomputed whenever GSC data refreshes; homepage is always present so
 * a site with no GSC connection still has a usable key-URL set.
 */
class KeyUrlService
{
    private const MAX_URLS = 4;

    /**
     * @return list<string>
     */
    public function derive(Site $site): array
    {
        $home = rtrim((string) $site->url, '/').'/';
        $host = parse_url($site->url, PHP_URL_HOST);

        $urls = [$home];

        $cache = SearchConsoleCache::query()
            ->where('site_id', $site->id)
            ->where('data_type', 'pages')
            ->orderByDesc('fetched_at')
            ->first();

        if ($cache) {
            $topPages = (new Collection($cache->data ?? []))
                ->sortByDesc(fn ($row) => (int) ($row['clicks'] ?? 0))
                ->map(fn ($row) => (string) ($row['page'] ?? ''))
                ->filter(fn ($url) => $url !== '' && parse_url($url, PHP_URL_HOST) === $host)
                ->values()
                ->all();

            $urls = array_merge($urls, $topPages);
        }

        // Dedupe (case/trailing-slash-insensitive on the home), cap the set.
        $seen = [];
        $out = [];
        foreach ($urls as $url) {
            $norm = rtrim($url, '/');
            if (isset($seen[$norm])) {
                continue;
            }
            $seen[$norm] = true;
            $out[] = $url;
            if (count($out) >= self::MAX_URLS) {
                break;
            }
        }

        return $out;
    }

    /**
     * Derive and persist onto sites.key_urls.
     *
     * @return list<string>
     */
    public function deriveAndStore(Site $site): array
    {
        $urls = $this->derive($site);
        $site->update(['key_urls' => $urls]);

        return $urls;
    }
}
