<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SearchConsoleCache;
use App\Models\Site;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SPEC §7.5 — a site's "URL-uri cheie", the handful of pages the update smoke
 * check (§7.4) and the report are judged against.
 *
 * The spec asks for four sources, a fallback, a cadence and a lock:
 *   1. homepage
 *   2. the top 3 pages by Search Console clicks
 *   3. the contact page, detected by its form
 *   4. a product or category page, if WooCommerce
 *   fallback (no Search Console): sitemap and the most-linked internal pages
 *   "Recalculare trimestrială, cu blocare pe cele suprascrise manual."
 *
 * Only the first two existed. Without a contact page in the set, a smoke check
 * could pass while the one page that generates enquiries was broken — and a site
 * with no Search Console connection had a key-URL set of exactly one entry.
 */
class KeyUrlService
{
    private const MAX_URLS = 5;

    /** How many Search Console pages to take, per the spec's "primele 3". */
    private const MAX_SEARCH_CONSOLE = 3;

    /** SPEC §7.5: "Recalculare trimestrială". */
    private const RECOMPUTE_AFTER_DAYS = 90;

    private const FETCH_TIMEOUT = 15;

    /**
     * @return list<string>
     */
    public function derive(Site $site): array
    {
        $home = rtrim((string) $site->url, '/').'/';

        $urls = array_merge(
            [$home],
            $this->fromSearchConsole($site),
            $this->fromContent($site),
        );

        // Search Console is the good source; only reach for the sitemap when it
        // left us with nothing but the homepage.
        if (count($urls) === 1) {
            $urls = array_merge($urls, $this->fromSitemap($site));
        }

        return $this->dedupe($urls);
    }

    /**
     * Derive and persist onto sites.key_urls.
     *
     * Refuses to touch a site whose key URLs were pinned by hand — the whole point
     * of the lock, since this runs on every sync and would otherwise erase the
     * choice within the hour.
     *
     * @return list<string>
     */
    public function deriveAndStore(Site $site): array
    {
        if ($site->key_urls_locked_at !== null) {
            return $this->currentUrls($site);
        }

        $urls = $this->derive($site);

        $site->update([
            'key_urls' => $urls,
            'key_urls_derived_at' => now(),
        ]);

        return $urls;
    }

    /**
     * The quarterly path (SPEC §7.5). Callers on the hot path — every WP sync,
     * every Search Console fetch — go through this rather than deriveAndStore, so
     * the set is recomputed four times a year instead of several times a day.
     *
     * @return list<string>
     */
    public function refreshIfDue(Site $site): array
    {
        if ($site->key_urls_locked_at !== null) {
            return $this->currentUrls($site);
        }

        $derivedAt = $site->key_urls_derived_at;
        $due = $derivedAt === null || $derivedAt->lt(now()->subDays(self::RECOMPUTE_AFTER_DAYS));

        // A site that has never had a set needs one now, quarter or not.
        if (! $due && $this->currentUrls($site) !== []) {
            return $this->currentUrls($site);
        }

        return $this->deriveAndStore($site);
    }

    /**
     * Pin a hand-picked set. Locked sets survive every subsequent derive.
     *
     * @param  list<string>  $urls
     */
    public function lock(Site $site, array $urls): void
    {
        $site->update([
            'key_urls' => $this->dedupe($urls),
            'key_urls_locked_at' => now(),
        ]);
    }

    /** Hand the set back to automatic derivation. */
    public function unlock(Site $site): void
    {
        $site->update(['key_urls_locked_at' => null]);
    }

    /**
     * @return list<string>
     */
    private function currentUrls(Site $site): array
    {
        return array_values(array_filter((array) ($site->key_urls ?? [])));
    }

    /**
     * Top pages by clicks, same host only.
     *
     * @return list<string>
     */
    private function fromSearchConsole(Site $site): array
    {
        $host = parse_url((string) $site->url, PHP_URL_HOST);

        $cache = SearchConsoleCache::query()
            ->where('site_id', $site->id)
            ->where('data_type', 'pages')
            ->orderByDesc('fetched_at')
            ->first();

        if (! $cache) {
            return [];
        }

        return (new Collection($cache->data ?? []))
            ->sortByDesc(fn ($row) => (int) ($row['clicks'] ?? 0))
            ->map(fn ($row) => (string) ($row['page'] ?? ''))
            ->filter(fn ($url) => $url !== '' && parse_url($url, PHP_URL_HOST) === $host)
            ->take(self::MAX_SEARCH_CONSOLE)
            ->values()
            ->all();
    }

    /**
     * The contact page and a shop page (SPEC §7.5 points 3 and 4).
     *
     * Both are read from the site's own content inventory rather than guessed from
     * a path: "/contact" is wrong on every Romanian site that uses "/contact-ro"
     * or "/despre-noi", and a guessed URL that 404s would poison the smoke check
     * with a permanent failure on a page that never existed.
     *
     * @return list<string>
     */
    private function fromContent(Site $site): array
    {
        $out = [];

        $contact = $this->firstUrlMatching($site, ['contact', 'contactati', 'contactează', 'kontakt']);
        if ($contact !== null) {
            $out[] = $contact;
        }

        // Only where WooCommerce is actually active — the inventory already knows.
        $hasWoo = $site->sitePlugins()
            ->where('slug', 'woocommerce')
            ->where('is_active', true)
            ->exists();

        if ($hasWoo) {
            $shop = $this->firstUrlMatching($site, ['shop', 'magazin', 'produs', 'product', 'categorie']);
            if ($shop !== null) {
                $out[] = $shop;
            }
        }

        return $out;
    }

    /**
     * Find a page whose URL contains one of the given words, using the URLs the
     * broken-link scan already pulled from the site's database. No extra request,
     * and no guessing at paths.
     *
     * @param  list<string>  $needles
     */
    private function firstUrlMatching(Site $site, array $needles): ?string
    {
        $host = parse_url((string) $site->url, PHP_URL_HOST);

        $candidates = \App\Models\LinkCheckResult::query()
            ->whereHas('run', fn ($q) => $q->where('site_id', $site->id))
            ->where('is_internal', true)
            ->where('is_broken', false)
            ->orderBy('url')
            ->limit(500)
            ->pluck('url');

        foreach ($candidates as $url) {
            if (parse_url((string) $url, PHP_URL_HOST) !== $host) {
                continue;
            }

            $path = strtolower((string) parse_url((string) $url, PHP_URL_PATH));

            foreach ($needles as $needle) {
                if ($path !== '' && $path !== '/' && str_contains($path, $needle)) {
                    return (string) $url;
                }
            }
        }

        return null;
    }

    /**
     * Fallback when there is no Search Console data: the first entries of the
     * site's sitemap. Without this a site with no GSC connection ended up with a
     * key-URL set of one — the homepage — so its smoke check only ever proved the
     * front page still rendered.
     *
     * @return list<string>
     */
    private function fromSitemap(Site $site): array
    {
        $host = parse_url((string) $site->url, PHP_URL_HOST);
        $base = rtrim((string) $site->url, '/');

        foreach (['/sitemap_index.xml', '/sitemap.xml', '/wp-sitemap.xml'] as $path) {
            try {
                $response = Http::timeout(self::FETCH_TIMEOUT)->get($base.$path);
            } catch (\Throwable $e) {
                Log::info('KeyUrlService: sitemap fetch failed', [
                    'site_id' => $site->id,
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (! $response->successful()) {
                continue;
            }

            // Deliberately a regex rather than an XML parser: sitemap indexes,
            // urlsets and the odd malformed feed all answer the same shape, and a
            // parse error here must never break a sync.
            if (preg_match_all('#<loc>\s*([^<\s]+)\s*</loc>#i', $response->body(), $matches) !== 1 && empty($matches[1])) {
                continue;
            }

            $urls = [];
            foreach ($matches[1] as $url) {
                $url = html_entity_decode((string) $url);

                // Skip nested sitemaps; one level of fetching is enough here.
                if (str_ends_with(strtolower($url), '.xml')) {
                    continue;
                }
                if (parse_url($url, PHP_URL_HOST) !== $host) {
                    continue;
                }

                $urls[] = $url;

                if (count($urls) >= self::MAX_URLS) {
                    break;
                }
            }

            if ($urls !== []) {
                return $urls;
            }
        }

        return [];
    }

    /**
     * @param  list<string>  $urls
     * @return list<string>
     */
    private function dedupe(array $urls): array
    {
        $seen = [];
        $out = [];

        foreach ($urls as $url) {
            $url = trim((string) $url);

            if ($url === '') {
                continue;
            }

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
}
