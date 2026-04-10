<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Models\CrawledPage;
use App\Models\Site;
use App\Models\SiteCrawl;
use App\Services\JobTracker;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SiteCrawlerService
{
    private const DEFAULT_MAX_PAGES = 500;

    private const HARD_MAX_PAGES = 2000;

    private const DEFAULT_MAX_DEPTH = 50;

    private const DEFAULT_RATE_LIMIT_MS = 1000;

    private const DEFAULT_USER_AGENT = 'SimpleAd SEO Crawler/1.0';

    private const DEFAULT_TIMEOUT = 15;

    private const MAX_REDIRECTS = 5;

    private const PROGRESS_REPORT_INTERVAL = 10;

    public function crawl(SiteCrawl $crawl, ?string $trackerKey = null): void
    {
        $config = $crawl->config ?? [];

        $maxPages = min(
            (int) ($config['max_pages'] ?? self::DEFAULT_MAX_PAGES),
            self::HARD_MAX_PAGES
        );
        $maxDepth = (int) ($config['max_depth'] ?? self::DEFAULT_MAX_DEPTH);
        $rateLimitMs = (int) ($config['rate_limit_ms'] ?? self::DEFAULT_RATE_LIMIT_MS);
        $userAgent = (string) ($config['user_agent'] ?? self::DEFAULT_USER_AGENT);
        $timeout = (int) ($config['timeout'] ?? self::DEFAULT_TIMEOUT);
        $respectRobots = (bool) ($config['respect_robots_txt'] ?? true);

        $crawl->update([
            'status' => SiteCrawl::STATUS_RUNNING,
            'started_at' => now(),
            'pages_crawled' => 0,
            'pages_found' => 0,
        ]);

        // Determine start URL: from start_url field, or from associated site
        $site = $crawl->site;
        $siteUrl = $crawl->start_url
            ? rtrim($crawl->start_url, '/')
            : ($site ? rtrim($site->url, '/') : null);

        if (! $siteUrl) {
            $crawl->update(['status' => SiteCrawl::STATUS_FAILED, 'completed_at' => now()]);

            return;
        }

        $siteHost = (string) parse_url($siteUrl, PHP_URL_HOST);

        $disallowRules = [];
        if ($respectRobots) {
            $disallowRules = $this->parseRobotsTxt($siteUrl);
        }

        // BFS queue: [url => depth]
        /** @var array<string, int> $queue */
        $queue = [$siteUrl => 0];
        /** @var array<string, true> $visited */
        $visited = [];

        $pageParser = new PageParser;
        $pagesCrawled = 0;
        $pagesFound = 1;

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 0, 'Starting crawl of '.$siteUrl);
        }

        try {
            while (! empty($queue) && $pagesCrawled < $maxPages) {
                // Reload crawl status periodically to detect cancellation
                if ($pagesCrawled > 0 && $pagesCrawled % 20 === 0) {
                    $crawl->refresh();
                    if ($crawl->status === SiteCrawl::STATUS_CANCELLED) {
                        Log::info("Crawl {$crawl->id} cancelled after {$pagesCrawled} pages.");
                        break;
                    }
                }

                // Dequeue first element
                reset($queue);
                $url = (string) key($queue);
                $depth = $queue[$url];
                unset($queue[$url]);

                // Normalize URL before visited check (strip fragment, trailing slash)
                $url = $this->normalizeUrlForCrawl($url);

                // Skip if already visited
                if (isset($visited[$url])) {
                    continue;
                }
                $visited[$url] = true;

                // Skip if blocked by robots.txt
                if ($respectRobots && $this->isBlockedByRobots($url, $disallowRules)) {
                    continue;
                }

                // Skip if not same host
                $urlHost = (string) parse_url($url, PHP_URL_HOST);
                if (! $this->isSameHost($urlHost, $siteHost)) {
                    continue;
                }

                // Rate limit (skip on first request)
                if ($pagesCrawled > 0 && $rateLimitMs > 0) {
                    usleep($rateLimitMs * 1000);
                }

                // Fetch the page
                $startTime = microtime(true);
                [$response, $finalUrl, $redirectStatusCode, $redirectUrl] = $this->fetchWithRedirects(
                    $url,
                    $userAgent,
                    $timeout
                );
                $responseTimeMs = (int) round((microtime(true) - $startTime) * 1000);

                if ($response === null) {
                    // Connection/timeout failure
                    CrawledPage::create([
                        'site_crawl_id' => $crawl->id,
                        'url' => $url,
                        'status_code' => 0,
                        'depth' => $depth,
                        'response_time_ms' => $responseTimeMs,
                        'issues' => [
                            [
                                'type' => 'request_failed',
                                'severity' => 'high',
                                'message' => 'HTTP request failed (timeout or connection error).',
                            ],
                        ],
                        'crawled_at' => now(),
                    ]);

                    $pagesCrawled++;
                    $crawl->increment('pages_crawled');

                    continue;
                }

                $statusCode = $response->status();
                $contentType = $response->header('Content-Type');
                $contentLength = (int) ($response->header('Content-Length') ?: strlen($response->body()));
                $xRobotsTag = $response->header('X-Robots-Tag') ?: null;

                $isHtml = str_contains(strtolower($contentType), 'text/html');

                // Use the final URL after redirects for storage and parsing
                $resolvedUrl = $finalUrl ?: $url;

                // Mark final URL as visited too (prevent re-crawl after redirect)
                if ($resolvedUrl !== $url) {
                    $visited[$this->normalizeUrlForCrawl($resolvedUrl)] = true;
                }

                $seoData = [];

                if ($isHtml && $statusCode >= 200 && $statusCode < 300) {
                    $seoData = $pageParser->parse($resolvedUrl, $response->body(), $siteHost);

                    // Enqueue discovered internal links (within depth limit)
                    if ($depth < $maxDepth) {
                        foreach ($seoData['internal_links'] ?? [] as $link) {
                            $linkUrl = $link['url'] ?? '';
                            $normalizedLinkUrl = $this->normalizeUrlForCrawl($linkUrl);
                            if ($normalizedLinkUrl !== '' && ! isset($visited[$normalizedLinkUrl]) && ! isset($queue[$normalizedLinkUrl])) {
                                $queue[$normalizedLinkUrl] = $depth + 1;
                                $pagesFound++;
                            }
                        }
                    }
                }

                CrawledPage::create(array_merge([
                    'site_crawl_id' => $crawl->id,
                    'url' => $resolvedUrl,
                    'status_code' => $statusCode,
                    'content_type' => $contentType ? mb_substr($contentType, 0, 255) : null,
                    'response_time_ms' => $responseTimeMs,
                    'content_length' => $contentLength,
                    'depth' => $depth,
                    'x_robots_tag' => $xRobotsTag ? mb_substr($xRobotsTag, 0, 1024) : null,
                    'redirect_url' => $redirectUrl,
                    'redirect_status_code' => $redirectStatusCode,
                    'title' => null,
                    'title_length' => 0,
                    'meta_description' => null,
                    'meta_desc_length' => 0,
                    'canonical_self_ref' => false,
                    'h1_tags' => [],
                    'h1_count' => 0,
                    'h2_count' => 0,
                    'h3_count' => 0,
                    'word_count' => 0,
                    'readability_score' => null,
                    'internal_links' => [],
                    'internal_links_count' => 0,
                    'external_links' => [],
                    'external_links_count' => 0,
                    'images' => [],
                    'images_count' => 0,
                    'images_without_alt' => 0,
                    'scripts' => [],
                    'stylesheets' => [],
                    'is_https' => str_starts_with($resolvedUrl, 'https://'),
                    'has_mixed_content' => false,
                    'structured_data_types' => [],
                    'hreflang' => [],
                    'issues' => [],
                    'crawled_at' => now(),
                ], $seoData));

                $pagesCrawled++;

                $crawl->update([
                    'pages_crawled' => $pagesCrawled,
                    'pages_found' => $pagesFound,
                ]);

                // Report progress every N pages
                if ($trackerKey && $pagesCrawled % self::PROGRESS_REPORT_INTERVAL === 0) {
                    $percent = $maxPages > 0
                        ? min(90, (int) round(($pagesCrawled / $maxPages) * 90))
                        : 0;

                    JobTracker::progress(
                        $trackerKey,
                        $percent,
                        "Crawled {$pagesCrawled} / {$maxPages} pages — {$pagesFound} found"
                    );
                }
            }

            // Post-crawl analysis
            if ($trackerKey) {
                JobTracker::progress($trackerKey, 92, 'Running post-crawl analysis...');
            }

            $analyzer = new CrawlAnalyzer;
            $summary = $analyzer->analyze($crawl);

            $crawl->update([
                'status' => SiteCrawl::STATUS_COMPLETED,
                'completed_at' => now(),
                'duration_seconds' => (int) now()->diffInSeconds($crawl->started_at),
                'pages_crawled' => $pagesCrawled,
                'pages_found' => $pagesFound,
                'pages_with_issues' => $summary['pages_with_issues'] ?? 0,
                'errors_count' => ($summary['status_4xx'] ?? 0) + ($summary['status_5xx'] ?? 0),
                'summary' => $summary,
            ]);
        } catch (\Throwable $e) {
            Log::error("Crawl {$crawl->id} failed: ".$e->getMessage(), [
                'exception' => $e,
                'site_id' => $site?->id,
            ]);

            $crawl->update([
                'status' => SiteCrawl::STATUS_FAILED,
                'completed_at' => now(),
                'duration_seconds' => (int) now()->diffInSeconds($crawl->started_at ?? now()),
                'pages_crawled' => $pagesCrawled ?? 0,
            ]);

            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // HTTP helpers
    // -------------------------------------------------------------------------

    /**
     * Fetch a URL, manually following redirects to capture the chain.
     * Returns [response, finalUrl, firstRedirectStatusCode, firstRedirectTargetUrl].
     *
     * @return array{0: Response|null, 1: string, 2: int|null, 3: string|null}
     */
    private function fetchWithRedirects(string $url, string $userAgent, int $timeout): array
    {
        $redirectStatusCode = null;
        $redirectUrl = null;
        $currentUrl = $url;
        $hops = 0;

        try {
            while ($hops <= self::MAX_REDIRECTS) {
                $response = Http::withOptions([
                    'allow_redirects' => false,
                    'verify' => false,
                ])
                    ->withUserAgent($userAgent)
                    ->timeout($timeout)
                    ->get($currentUrl);

                $status = $response->status();

                if ($status >= 300 && $status < 400) {
                    $location = $response->header('Location');

                    if ($redirectStatusCode === null) {
                        $redirectStatusCode = $status;
                        $redirectUrl = $location ?: null;
                    }

                    if (! $location) {
                        return [$response, $currentUrl, $redirectStatusCode, $redirectUrl];
                    }

                    // Resolve relative redirects
                    if (! str_starts_with($location, 'http://') && ! str_starts_with($location, 'https://')) {
                        $parsed = parse_url($currentUrl);
                        $scheme = $parsed['scheme'] ?? 'https';
                        $host = $parsed['host'] ?? '';

                        if (str_starts_with($location, '/')) {
                            $location = $scheme.'://'.$host.$location;
                        } else {
                            $location = $scheme.'://'.$host.'/'.$location;
                        }
                    }

                    $currentUrl = rtrim($location, '/');
                    $hops++;

                    continue;
                }

                return [$response, $currentUrl, $redirectStatusCode, $redirectUrl];
            }

            // Too many redirects — return last response as-is
            return [null, $currentUrl, $redirectStatusCode, $redirectUrl];
        } catch (\Throwable) {
            return [null, $currentUrl, $redirectStatusCode, $redirectUrl];
        }
    }

    // -------------------------------------------------------------------------
    // Robots.txt
    // -------------------------------------------------------------------------

    /**
     * Fetch and parse robots.txt, returning Disallow paths for User-agent: *.
     *
     * @return string[]
     */
    private function parseRobotsTxt(string $siteUrl): array
    {
        $robotsUrl = rtrim($siteUrl, '/').'/robots.txt';
        $disallowed = [];

        try {
            $response = Http::withOptions(['verify' => false])
                ->withUserAgent(self::DEFAULT_USER_AGENT)
                ->timeout(10)
                ->get($robotsUrl);

            if (! $response->successful()) {
                return $disallowed;
            }

            $lines = explode("\n", $response->body());
            $activeAgent = false;

            foreach ($lines as $line) {
                $line = trim($line);

                // Strip inline comments
                if (str_contains($line, '#')) {
                    $line = trim(substr($line, 0, (int) strpos($line, '#')));
                }

                if ($line === '') {
                    // Blank line resets active agent block
                    $activeAgent = false;

                    continue;
                }

                if (stripos($line, 'User-agent:') === 0) {
                    $agent = trim(substr($line, strlen('User-agent:')));
                    $activeAgent = $agent === '*';

                    continue;
                }

                if ($activeAgent && stripos($line, 'Disallow:') === 0) {
                    $path = trim(substr($line, strlen('Disallow:')));
                    if ($path !== '') {
                        $disallowed[] = $path;
                    }
                }
            }
        } catch (\Throwable) {
            // If robots.txt is unavailable, proceed with no restrictions
        }

        return $disallowed;
    }

    /**
     * @param  string[]  $disallowRules
     */
    private function isBlockedByRobots(string $url, array $disallowRules): bool
    {
        if (empty($disallowRules)) {
            return false;
        }

        $path = (string) parse_url($url, PHP_URL_PATH);

        foreach ($disallowRules as $rule) {
            if (str_starts_with($path, $rule)) {
                return true;
            }
        }

        return false;
    }

    // -------------------------------------------------------------------------
    // URL helpers
    // -------------------------------------------------------------------------

    private function isSameHost(string $linkHost, string $siteHost): bool
    {
        $normalise = static fn (string $h): string => preg_replace('/^www\./', '', strtolower($h)) ?? strtolower($h);

        return $normalise($linkHost) === $normalise($siteHost);
    }

    /**
     * Normalize URL for crawl queue: strip fragment, normalize trailing slash, lowercase host.
     */
    private function normalizeUrlForCrawl(string $url): string
    {
        // Strip fragment
        $url = preg_replace('/#.*$/', '', $url) ?? $url;

        // Parse and rebuild with lowercase host
        $parsed = parse_url($url);
        if (! $parsed || ! isset($parsed['host'])) {
            return rtrim($url, '/');
        }

        $scheme = $parsed['scheme'] ?? 'https';
        $host = strtolower($parsed['host']);
        $path = $parsed['path'] ?? '/';
        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';

        // Normalize trailing slash on path (keep / for root, strip for others)
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        return $scheme.'://'.$host.$path.$query;
    }

    /**
     * Normalise a URL: strip fragment, strip trailing slash, return null for non-HTTP(S).
     */
    private function normalizeUrl(string $url, string $baseUrl): ?string
    {
        // Remove fragment
        $url = preg_replace('/#.*$/', '', $url) ?? $url;

        if (! str_starts_with($url, 'http://') && ! str_starts_with($url, 'https://')) {
            // Relative — resolve against base
            if (str_starts_with($url, '/')) {
                $parsed = parse_url($baseUrl);
                $url = ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '').$url;
            } elseif (str_starts_with($url, '//')) {
                $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?? 'https';
                $url = $scheme.':'.$url;
            } else {
                return null;
            }
        }

        return rtrim($url, '/');
    }
}
