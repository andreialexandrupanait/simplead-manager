<?php

namespace App\Services;

use App\Models\Link;
use App\Models\LinkMonitor;
use App\Models\LinkScan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LinkCheckerService
{
    private array $visited = [];
    private array $pendingLinks = [];
    private array $externalQueue = [];
    private int $pagesScanned = 0;
    private string $baseHost;
    private string $baseUrl;

    public function __construct(
        private LinkMonitor $monitor,
        private LinkScan $scan,
    ) {
        $this->baseUrl = rtrim($this->monitor->site->url, '/');
        $this->baseHost = parse_url($this->baseUrl, PHP_URL_HOST);
    }

    public function scan(): void
    {
        $this->scan->update([
            'status' => 'in_progress',
            'started_at' => now(),
            'progress_percent' => 0,
            'progress_message' => 'Starting scan...',
        ]);

        try {
            $this->bfsCrawl();

            if ($this->monitor->check_external && !empty($this->externalQueue)) {
                $this->checkExternalLinks();
            }

            $this->finalize();
        } catch (\Exception $e) {
            $this->scan->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'duration_seconds' => $this->scan->started_at ? (int) now()->diffInSeconds($this->scan->started_at) : null,
            ]);

            throw $e;
        }
    }

    private function bfsCrawl(): void
    {
        $queue = [$this->baseUrl];
        $depth = 0;
        $maxPages = $this->monitor->max_pages;
        $maxDepth = $this->monitor->max_depth;

        while (!empty($queue) && $depth <= $maxDepth && $this->pagesScanned < $maxPages) {
            $nextLevel = [];

            foreach ($queue as $url) {
                if ($this->pagesScanned >= $maxPages) {
                    break;
                }

                $normalizedUrl = $this->normalizeUrl($url);
                if (isset($this->visited[$normalizedUrl])) {
                    continue;
                }

                $this->visited[$normalizedUrl] = true;
                $this->pagesScanned++;

                $this->scan->update([
                    'progress_percent' => min(90, intval(($this->pagesScanned / $maxPages) * 90)),
                    'progress_message' => "Scanning page {$this->pagesScanned}/{$maxPages}: " . $this->truncateUrl($url),
                    'pages_scanned' => $this->pagesScanned,
                ]);

                $links = $this->crawlPage($url);

                foreach ($links as $link) {
                    $processed = $this->processLink($link, $url);
                    if ($processed === null) {
                        continue;
                    }

                    $this->pendingLinks[] = $processed;

                    // Queue internal links for further crawling
                    if ($processed['type'] === 'internal'
                        && $processed['link_type'] === 'anchor'
                        && !isset($this->visited[$this->normalizeUrl($processed['url'])])
                    ) {
                        $nextLevel[] = $processed['url'];
                    }
                }
            }

            $queue = array_unique($nextLevel);
            $depth++;
        }
    }

    private function crawlPage(string $url): array
    {
        $links = [];

        try {
            $response = Http::timeout($this->monitor->timeout_seconds)
                ->withOptions(['verify' => false])
                ->get($url);

            if (!$response->successful()) {
                return [];
            }

            $contentType = $response->header('Content-Type');
            if ($contentType && !str_contains($contentType, 'text/html')) {
                return [];
            }

            $html = $response->body();
            $links = $this->extractLinks($html, $url);
        } catch (\Exception $e) {
            Log::debug("LinkChecker: Failed to crawl {$url}: {$e->getMessage()}");
        }

        return $links;
    }

    private function extractLinks(string $html, string $sourceUrl): array
    {
        $links = [];

        // Suppress DOMDocument warnings for malformed HTML
        $doc = new \DOMDocument();
        @$doc->loadHTML($html, LIBXML_NOERROR);

        $pageTitle = '';
        $titleNodes = $doc->getElementsByTagName('title');
        if ($titleNodes->length > 0) {
            $pageTitle = trim($titleNodes->item(0)->textContent);
        }

        // Extract anchors
        foreach ($doc->getElementsByTagName('a') as $node) {
            $href = $node->getAttribute('href');
            if ($href) {
                $links[] = [
                    'url' => $href,
                    'anchor_text' => trim($node->textContent),
                    'element' => 'a',
                    'link_type' => 'anchor',
                    'source_url' => $sourceUrl,
                    'source_title' => $pageTitle,
                ];
            }
        }

        // Extract images
        if ($this->monitor->check_images) {
            foreach ($doc->getElementsByTagName('img') as $node) {
                $src = $node->getAttribute('src');
                if ($src) {
                    $links[] = [
                        'url' => $src,
                        'anchor_text' => $node->getAttribute('alt') ?: null,
                        'element' => 'img',
                        'link_type' => 'image',
                        'source_url' => $sourceUrl,
                        'source_title' => $pageTitle,
                    ];
                }
            }
        }

        // Extract scripts
        foreach ($doc->getElementsByTagName('script') as $node) {
            $src = $node->getAttribute('src');
            if ($src) {
                $links[] = [
                    'url' => $src,
                    'anchor_text' => null,
                    'element' => 'script',
                    'link_type' => 'script',
                    'source_url' => $sourceUrl,
                    'source_title' => $pageTitle,
                ];
            }
        }

        // Extract stylesheets
        foreach ($doc->getElementsByTagName('link') as $node) {
            if (strtolower($node->getAttribute('rel')) === 'stylesheet') {
                $href = $node->getAttribute('href');
                if ($href) {
                    $links[] = [
                        'url' => $href,
                        'anchor_text' => null,
                        'element' => 'link',
                        'link_type' => 'stylesheet',
                        'source_url' => $sourceUrl,
                        'source_title' => $pageTitle,
                    ];
                }
            }
        }

        return $links;
    }

    private function processLink(array $link, string $currentUrl): ?array
    {
        $url = $link['url'];

        // Skip non-http(s) schemes
        if (preg_match('/^(mailto:|tel:|javascript:|data:|#|$)/', $url)) {
            return null;
        }

        // Resolve relative URLs
        $url = $this->resolveUrl($url, $currentUrl);

        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $linkHost = parse_url($url, PHP_URL_HOST);
        $isInternal = $this->isInternalHost($linkHost);

        // Check exclusions
        if ($isInternal && $this->isExcludedPath($url)) {
            return null;
        }

        if (!$isInternal && $this->isExcludedDomain($linkHost)) {
            return null;
        }

        $urlHash = hash('sha256', $url);

        $result = [
            'url' => $url,
            'url_hash' => $urlHash,
            'type' => $isInternal ? 'internal' : 'external',
            'link_type' => $link['link_type'],
            'source_url' => $link['source_url'],
            'source_title' => $link['source_title'],
            'anchor_text' => $link['anchor_text'] ? mb_substr($link['anchor_text'], 0, 500) : null,
            'element' => $link['element'],
            'status' => 'pending',
            'first_detected_at' => now(),
        ];

        // Check internal links immediately
        if ($isInternal) {
            $check = $this->checkUrl($url);
            $result = array_merge($result, $check);
        } else {
            // Queue external links for batch checking
            $this->externalQueue[$urlHash] = $result;
            return $result;
        }

        return $result;
    }

    private function checkExternalLinks(): void
    {
        $total = count($this->externalQueue);
        $checked = 0;

        $this->scan->update([
            'progress_message' => "Checking {$total} external links...",
        ]);

        foreach ($this->externalQueue as $urlHash => $link) {
            $check = $this->checkUrl($link['url']);

            // Update the pending link in our list
            foreach ($this->pendingLinks as &$pending) {
                if (($pending['url_hash'] ?? '') === $urlHash && $pending['status'] === 'pending') {
                    $pending = array_merge($pending, $check);
                    break;
                }
            }
            unset($pending);

            $checked++;
            if ($checked % 10 === 0) {
                $this->scan->update([
                    'progress_message' => "Checking external links: {$checked}/{$total}",
                ]);
            }

            // Small delay to avoid overwhelming external servers
            usleep(200000); // 200ms
        }
    }

    private function checkUrl(string $url): array
    {
        $result = [
            'status' => 'ok',
            'http_code' => null,
            'final_url' => null,
            'redirect_count' => 0,
            'response_time_ms' => null,
            'error_message' => null,
            'is_permanent_redirect' => false,
            'last_checked_at' => now(),
        ];

        $start = microtime(true);

        try {
            $response = Http::timeout($this->monitor->timeout_seconds)
                ->withOptions([
                    'verify' => false,
                    'allow_redirects' => [
                        'max' => 10,
                        'track_redirects' => true,
                    ],
                ])
                ->get($url);

            $elapsed = intval((microtime(true) - $start) * 1000);
            $result['response_time_ms'] = $elapsed;
            $result['http_code'] = $response->status();

            // Check for redirects
            $redirectHistory = $response->handlerStats()['redirect_url'] ?? null;
            $redirectCount = $response->handlerStats()['redirect_count'] ?? 0;

            if ($redirectCount > 0 || $response->effectiveUri()?->__toString() !== $url) {
                $effectiveUrl = $response->effectiveUri()?->__toString() ?? $url;
                if ($effectiveUrl !== $url) {
                    $result['redirect_count'] = max($redirectCount, 1);
                    $result['final_url'] = $effectiveUrl;
                    $result['status'] = 'redirect';

                    // Check if permanent redirect via original status
                    $originalStatus = $response->handlerStats()['http_code'] ?? $response->status();
                    $result['is_permanent_redirect'] = in_array($originalStatus, [301, 308]);
                }
            }

            // Check for errors
            if ($response->status() >= 400) {
                $result['status'] = 'broken';
            }
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            $elapsed = intval((microtime(true) - $start) * 1000);
            $result['response_time_ms'] = $elapsed;

            $message = $e->getMessage();
            if (str_contains($message, 'timed out') || str_contains($message, 'timeout')) {
                $result['status'] = 'timeout';
                $result['error_message'] = 'Connection timed out';
            } elseif (str_contains($message, 'SSL') || str_contains($message, 'certificate')) {
                $result['status'] = 'ssl_error';
                $result['error_message'] = 'SSL certificate error';
            } elseif (str_contains($message, 'resolve') || str_contains($message, 'DNS')) {
                $result['status'] = 'dns_error';
                $result['error_message'] = 'DNS resolution failed';
            } else {
                $result['status'] = 'broken';
                $result['error_message'] = mb_substr($message, 0, 500);
            }
        } catch (\Exception $e) {
            $elapsed = intval((microtime(true) - $start) * 1000);
            $result['response_time_ms'] = $elapsed;
            $result['status'] = 'broken';
            $result['error_message'] = mb_substr($e->getMessage(), 0, 500);
        }

        return $result;
    }

    private function finalize(): void
    {
        $this->scan->update([
            'progress_percent' => 95,
            'progress_message' => 'Saving results...',
        ]);

        // Deduplicate by url_hash — keep the worst status for each URL
        $deduped = [];
        $statusPriority = ['broken' => 0, 'ssl_error' => 1, 'dns_error' => 2, 'timeout' => 3, 'redirect' => 4, 'pending' => 5, 'ok' => 6];

        foreach ($this->pendingLinks as $link) {
            $hash = $link['url_hash'];
            if (!isset($deduped[$hash]) || ($statusPriority[$link['status']] ?? 5) < ($statusPriority[$deduped[$hash]['status']] ?? 5)) {
                $deduped[$hash] = $link;
            }
        }

        // Bulk insert in chunks
        $siteId = $this->monitor->site_id;
        $scanId = $this->scan->id;

        $chunks = array_chunk(array_values($deduped), 500);
        foreach ($chunks as $chunk) {
            $rows = [];
            foreach ($chunk as $link) {
                $rows[] = [
                    'site_id' => $siteId,
                    'link_scan_id' => $scanId,
                    'url' => mb_substr($link['url'], 0, 2048),
                    'url_hash' => $link['url_hash'],
                    'type' => $link['type'],
                    'link_type' => $link['link_type'],
                    'source_url' => $link['source_url'] ? mb_substr($link['source_url'], 0, 2048) : null,
                    'source_title' => $link['source_title'] ? mb_substr($link['source_title'], 0, 255) : null,
                    'anchor_text' => $link['anchor_text'],
                    'element' => $link['element'],
                    'status' => $link['status'],
                    'http_code' => isset($link['http_code']) ? (int) $link['http_code'] : null,
                    'final_url' => isset($link['final_url']) ? mb_substr($link['final_url'], 0, 2048) : null,
                    'redirect_count' => (int) ($link['redirect_count'] ?? 0),
                    'response_time_ms' => isset($link['response_time_ms']) ? (int) $link['response_time_ms'] : null,
                    'error_message' => $link['error_message'] ?? null,
                    'is_permanent_redirect' => $link['is_permanent_redirect'] ?? false,
                    'is_dismissed' => false,
                    'first_detected_at' => $link['first_detected_at'] ?? now(),
                    'last_checked_at' => $link['last_checked_at'] ?? null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
            Link::insert($rows);
        }

        // Calculate stats
        $totalLinks = count($deduped);
        $brokenCount = collect($deduped)->where('status', 'broken')->count();
        $redirectCount = collect($deduped)->where('status', 'redirect')->count();
        $timeoutCount = collect($deduped)->where('status', 'timeout')->count();

        $completedAt = now();
        $duration = $this->scan->started_at ? (int) $completedAt->diffInSeconds($this->scan->started_at) : null;

        // Update scan
        $this->scan->update([
            'status' => 'completed',
            'total_links' => $totalLinks,
            'broken_links' => $brokenCount,
            'redirects' => $redirectCount,
            'timeouts' => $timeoutCount,
            'pages_scanned' => $this->pagesScanned,
            'progress_percent' => 100,
            'progress_message' => 'Scan complete',
            'completed_at' => $completedAt,
            'duration_seconds' => $duration,
        ]);

        // Update monitor cached stats
        $this->monitor->update([
            'total_links' => $totalLinks,
            'broken_links' => $brokenCount,
            'redirects' => $redirectCount,
            'pages_scanned' => $this->pagesScanned,
            'last_scan_at' => $completedAt,
            'last_scan_status' => 'completed',
        ]);
    }

    private function resolveUrl(string $url, string $baseUrl): ?string
    {
        // Already absolute
        if (preg_match('/^https?:\/\//', $url)) {
            return $url;
        }

        // Protocol-relative
        if (str_starts_with($url, '//')) {
            $scheme = parse_url($baseUrl, PHP_URL_SCHEME) ?: 'https';
            return $scheme . ':' . $url;
        }

        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        $port = isset($parsed['port']) ? ':' . $parsed['port'] : '';

        // Root-relative
        if (str_starts_with($url, '/')) {
            return "{$scheme}://{$host}{$port}{$url}";
        }

        // Relative URL
        $basePath = $parsed['path'] ?? '/';
        $baseDir = substr($basePath, 0, strrpos($basePath, '/') + 1);

        return "{$scheme}://{$host}{$port}{$baseDir}{$url}";
    }

    private function normalizeUrl(string $url): string
    {
        $parsed = parse_url($url);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = strtolower($parsed['host'] ?? '');
        $path = rtrim($parsed['path'] ?? '/', '/') ?: '/';

        return "{$scheme}://{$host}{$path}";
    }

    private function isInternalHost(?string $host): bool
    {
        if (!$host) {
            return true;
        }

        $host = strtolower($host);
        $base = strtolower($this->baseHost);

        // Exact match or www variant
        return $host === $base
            || $host === 'www.' . $base
            || 'www.' . $host === $base;
    }

    private function isExcludedPath(string $url): bool
    {
        $paths = $this->monitor->exclude_paths ?? [];
        if (empty($paths)) {
            return false;
        }

        $urlPath = parse_url($url, PHP_URL_PATH) ?? '/';

        foreach ($paths as $pattern) {
            $pattern = trim($pattern);
            if ($pattern && str_starts_with($urlPath, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isExcludedDomain(?string $host): bool
    {
        if (!$host) {
            return false;
        }

        $domains = $this->monitor->exclude_domains ?? [];
        if (empty($domains)) {
            return false;
        }

        $host = strtolower($host);

        foreach ($domains as $domain) {
            $domain = strtolower(trim($domain));
            if ($domain && ($host === $domain || str_ends_with($host, '.' . $domain))) {
                return true;
            }
        }

        return false;
    }

    private function truncateUrl(string $url): string
    {
        return strlen($url) > 60 ? substr($url, 0, 57) . '...' : $url;
    }
}
