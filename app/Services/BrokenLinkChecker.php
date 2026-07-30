<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LinkCheckResult;
use App\Models\LinkCheckRun;
use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Faza 5.3 — broken-link detection.
 *
 * Pulls the site's referenced URLs from the connector (/content-urls), HEAD-checks
 * each from the MANAGER's network (a loopback HEAD on the WP host would hit
 * Cloudflare 403 — see CLAUDE.md), follows internal redirect chains, writes a full
 * per-run snapshot, diffs it against the previous run, and — only when a NEW broken
 * link sits on a page that ranks in Search Console — raises a slim alert. Everything
 * else lands in the run report.
 *
 * External links are skipped unless the per-site `check_external_links` flag is on.
 */
class BrokenLinkChecker
{
    /** HEAD/GET timeout per URL (seconds). */
    private const REQUEST_TIMEOUT = 10;

    /** Max redirect hops followed while building an internal chain. */
    private const MAX_HOPS = 10;

    /** Max /content-urls pages pulled from the connector per run. */
    private const MAX_PAGES = 50;

    private const REDIRECT_CODES = [301, 302, 303, 307, 308];

    public function check(Site $site): LinkCheckRun
    {
        $run = LinkCheckRun::create([
            'site_id' => $site->id,
            'started_at' => now(),
        ]);

        // Per-site opt-in flag. Read via getAttribute so this service stays
        // decoupled from the shared Site model (no accessor/cast added there).
        $checkExternal = filter_var($site->getAttribute('check_external_links'), FILTER_VALIDATE_BOOLEAN);

        // 1. Gather URLs from the connector (paginated). Keyed by URL so sources merge.
        $urls = $this->fetchContentUrls($site);

        $totalUrls = 0;
        $brokenCount = 0;
        $chainsCount = 0;

        // Broken URL => list of its source pages (for the SC-gated alert).
        $currentBroken = [];

        foreach ($urls as $url => $meta) {
            $isInternal = $meta['is_internal'];

            // Internal always; external only when explicitly enabled per-site.
            if (! $isInternal && ! $checkExternal) {
                continue;
            }

            $totalUrls++;

            $check = $this->checkUrl($url, $isInternal);
            $chain = $check['chain'];
            $isChain = count($chain) > 1;

            if ($isChain) {
                $chainsCount++;
            }
            if ($check['is_broken']) {
                $brokenCount++;
                $currentBroken[$url] = $meta['source_pages'];
            }

            LinkCheckResult::create([
                'run_id' => $run->id,
                'url' => $url,
                'source_page' => $meta['source_pages'][0] ?? null,
                'status_code' => $check['status_code'],
                'is_internal' => $isInternal,
                'is_broken' => $check['is_broken'],
                'redirect_chain' => $isChain ? $chain : null,
            ]);
        }

        // 2. Diff against the previous run (URL-set based).
        $previousBroken = $this->previousBrokenUrls($site, $run->id);
        $newBroken = array_values(array_diff(array_keys($currentBroken), $previousBroken));
        $resolved = array_values(array_diff($previousBroken, array_keys($currentBroken)));

        $run->update([
            'finished_at' => now(),
            'total_urls' => $totalUrls,
            'broken_count' => $brokenCount,
            'chains_count' => $chainsCount,
            'new_broken_count' => count($newBroken),
            'resolved_count' => count($resolved),
        ]);

        // 3. Alert only when a NEW broken link is on a top Search Console page.
        $this->maybeAlert($site, $newBroken, $currentBroken);

        return $run->refresh();
    }

    /**
     * @return array<string, array{is_internal: bool, source_pages: string[]}>
     */
    private function fetchContentUrls(Site $site): array
    {
        $api = app(WordPressApiServiceFactory::class)->make($site);

        $collected = [];
        $page = 1;

        do {
            try {
                $response = $api->request('GET', '/content-urls', [], ['page' => $page, 'per_page' => 100], 30);
            } catch (\Throwable $e) {
                Log::warning('BrokenLinkChecker: /content-urls fetch failed', [
                    'site_id' => $site->id,
                    'page' => $page,
                    'error' => $e->getMessage(),
                ]);
                break;
            }

            if (! $response->successful()) {
                break;
            }

            $json = $response->json();
            foreach (($json['urls'] ?? []) as $entry) {
                $url = $entry['url'] ?? null;
                if (! is_string($url) || $url === '') {
                    continue;
                }
                if (! isset($collected[$url])) {
                    $collected[$url] = [
                        'is_internal' => (bool) ($entry['is_internal'] ?? false),
                        'source_pages' => [],
                    ];
                }
                foreach (($entry['source_pages'] ?? []) as $src) {
                    if (is_string($src) && $src !== '' && ! in_array($src, $collected[$url]['source_pages'], true)) {
                        $collected[$url]['source_pages'][] = $src;
                    }
                }
            }

            $hasMore = (bool) ($json['has_more'] ?? false);
            $page++;
        } while ($hasMore && $page <= self::MAX_PAGES);

        return $collected;
    }

    /**
     * HEAD-check a URL (GET fallback on 405), following internal redirect chains.
     *
     * @return array{status_code: int|null, is_broken: bool, chain: array<int, array{url: string, status_code: int}>}
     */
    private function checkUrl(string $url, bool $followRedirects): array
    {
        $chain = [];
        $current = $url;
        $status = null;

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            [$status, $location] = $this->probe($current);
            $chain[] = ['url' => $current, 'status_code' => (int) $status];

            $isRedirect = $status !== null
                && in_array($status, self::REDIRECT_CODES, true)
                && $location !== null && $location !== '';

            if (! $followRedirects || ! $isRedirect) {
                break;
            }

            $next = $this->resolveLocation($current, $location);
            if ($next === null || $next === $current) {
                break;
            }
            $current = $next;
        }

        $isBroken = $status === null || $status === 0 || $status >= 400;

        return [
            'status_code' => $status,
            'is_broken' => $isBroken,
            'chain' => $chain,
        ];
    }

    /**
     * Single HEAD probe (GET fallback on 405), redirects NOT auto-followed so the
     * chain can be recorded hop-by-hop.
     *
     * @return array{0: int|null, 1: string|null} [status, location]
     */
    private function probe(string $url): array
    {
        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT)
                ->withOptions(['allow_redirects' => false])
                ->head($url);

            if ($response->status() === 405) {
                $response = Http::timeout(self::REQUEST_TIMEOUT)
                    ->withOptions(['allow_redirects' => false])
                    ->get($url);
            }

            return [$response->status(), $response->header('Location') ?: null];
        } catch (\Throwable $e) {
            // Connection failure / DNS / TLS — treat as broken (status 0).
            return [0, null];
        }
    }

    private function resolveLocation(string $base, string $location): ?string
    {
        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        if (! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $origin = $parts['scheme'].'://'.$parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':'.$parts['port'];
        }

        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }

        $path = isset($parts['path']) ? preg_replace('#/[^/]*$#', '/', $parts['path']) : '/';

        return $origin.$path.$location;
    }

    /**
     * @return string[] broken URLs from the site's most recent prior run
     */
    private function previousBrokenUrls(Site $site, int $currentRunId): array
    {
        $previous = LinkCheckRun::where('site_id', $site->id)
            ->where('id', '!=', $currentRunId)
            ->whereNotNull('finished_at')
            ->orderByDesc('id')
            ->first();

        if (! $previous) {
            return [];
        }

        return LinkCheckResult::where('run_id', $previous->id)
            ->where('is_broken', true)
            ->pluck('url')
            ->all();
    }

    /**
     * @param  string[]  $newBroken
     * @param  array<string, string[]>  $currentBroken  broken URL => source pages
     */
    private function maybeAlert(Site $site, array $newBroken, array $currentBroken): void
    {
        if ($newBroken === []) {
            return;
        }

        $topPages = $this->topSearchConsolePages($site);
        if ($topPages === []) {
            return;
        }

        $flagged = [];
        foreach ($newBroken as $url) {
            foreach (($currentBroken[$url] ?? []) as $source) {
                if (in_array($this->canonicalPage($source), $topPages, true)) {
                    $flagged[$url] = true;
                    break;
                }
            }
        }

        if ($flagged === []) {
            return;
        }

        $count = count($flagged);
        $noun = $count === 1 ? 'broken link' : 'broken links';
        $summary = "\xF0\x9F\x94\x97 Broken links · *{$site->name}* — {$count} new {$noun} on a top Search Console page.";

        NotificationService::notifySiteEventSlim(
            site: $site,
            event: 'broken_links_detected',
            summary: $summary,
            deepLink: '<'.route('sites.overview', $site).'|Open site →>',
            severity: 'warning',
        );
    }

    /**
     * @return string[] canonicalised top page URLs from the latest SC "pages" cache
     */
    private function topSearchConsolePages(Site $site): array
    {
        $cache = SearchConsoleCache::where('site_id', $site->id)
            ->where('data_type', 'pages')
            ->orderByDesc('fetched_at')
            ->first();

        if (! $cache || empty($cache->data)) {
            return [];
        }

        $pages = [];
        foreach ($cache->data as $row) {
            $page = is_array($row) ? ($row['page'] ?? null) : null;
            if (is_string($page) && $page !== '') {
                $pages[] = $this->canonicalPage($page);
            }
        }

        return array_values(array_unique($pages));
    }

    private function canonicalPage(string $url): string
    {
        $url = strtok($url, '#') ?: $url;
        $url = rtrim($url, '/');

        return strtolower($url);
    }
}
