<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Backlink;
use App\Models\Site;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class BacklinkCrawlerService
{
    private const USER_AGENT = 'SimpleAd Backlink Verifier/1.0';

    private const TIMEOUT = 10;

    private const MAX_PAGES_PER_SYNC = 200;

    private const RATE_LIMIT_MS = 500;

    /**
     * Crawl referring pages to extract backlink details.
     *
     * Takes source URLs (from GSC or existing unverified backlinks),
     * visits each page, finds the link to our site, extracts details.
     */
    public function crawlReferringPages(Site $site, array $sourceUrls): int
    {
        $siteHost = $this->normalizeDomain($site->url);
        $siteUrl = rtrim($site->url, '/');
        $siteName = $site->name ?? $siteHost;
        $crawled = 0;
        $today = now()->toDateString();

        $urls = array_slice($sourceUrls, 0, self::MAX_PAGES_PER_SYNC);

        foreach ($urls as $sourceUrl) {
            if ($crawled > 0) {
                usleep(self::RATE_LIMIT_MS * 1000);
            }

            try {
                $response = Http::withOptions(['verify' => false])
                    ->withUserAgent(self::USER_AGENT)
                    ->timeout(self::TIMEOUT)
                    ->get($sourceUrl);

                if (! $response->successful()) {
                    continue;
                }

                $html = $response->body();
                $contentType = $response->header('Content-Type') ?? '';

                if (! str_contains(strtolower($contentType), 'text/html')) {
                    continue;
                }

                $pageData = $this->parsePage($html, $sourceUrl, $siteHost, $siteUrl);

                if (empty($pageData['links_to_site'])) {
                    // Page doesn't link to us (anymore)
                    Backlink::where('site_id', $site->id)
                        ->where('source_url', $sourceUrl)
                        ->whereNull('lost_at')
                        ->update([
                            'is_alive' => false,
                            'lost_at' => $today,
                            'last_verified_at' => now(),
                        ]);

                    continue;
                }

                $sourceDomain = $this->normalizeDomain($sourceUrl);

                foreach ($pageData['links_to_site'] as $link) {
                    $anchorType = app(BacklinkService::class)->classifyAnchorText(
                        $link['anchor_text'] ?? '',
                        $siteHost,
                        $siteName,
                        $site,
                    );

                    $spamScore = app(BacklinkService::class)->calculateSpamScore(
                        $sourceDomain,
                        $link['anchor_text'] ?? null,
                        $link['is_nofollow'],
                        $pageData['quality'],
                    );

                    Backlink::updateOrCreate(
                        [
                            'site_id' => $site->id,
                            'source_url' => $sourceUrl,
                            'target_url' => $link['href'],
                        ],
                        [
                            'source_domain' => $sourceDomain,
                            'anchor_text' => ! empty($link['anchor_text']) ? mb_substr($link['anchor_text'], 0, 500) : null,
                            'is_nofollow' => $link['is_nofollow'],
                            'link_type' => $link['is_nofollow'] ? 'nofollow' : 'dofollow',
                            'first_seen_at' => $today,
                            'last_seen_at' => $today,
                            'lost_at' => null,
                            'source_type' => 'crawl',
                            'spam_score' => $spamScore,
                            'page_title' => $pageData['title'] ? mb_substr($pageData['title'], 0, 500) : null,
                            'context_text' => ! empty($link['context']) ? mb_substr($link['context'], 0, 500) : null,
                            'outbound_links_count' => $pageData['quality']['outbound_links'] ?? null,
                            'link_position' => $link['position'] ?? null,
                            'anchor_type' => $anchorType,
                            'last_verified_at' => now(),
                            'is_alive' => true,
                        ],
                    );
                }

                $crawled++;
            } catch (\Throwable $e) {
                Log::debug("Backlink crawl failed for {$sourceUrl}: {$e->getMessage()}");

                continue;
            }
        }

        return $crawled;
    }

    /**
     * Re-crawl existing backlinks to verify they still exist.
     */
    public function verifyExistingBacklinks(Site $site, int $limit = 100): array
    {
        $backlinks = Backlink::where('site_id', $site->id)
            ->active()
            ->where('is_alive', true)
            ->where('source_type', '!=', 'gsc')
            ->where(function ($q) {
                $q->whereNull('last_verified_at')
                    ->orWhere('last_verified_at', '<', now()->subDays(7));
            })
            ->orderBy('last_verified_at')
            ->limit($limit)
            ->get();

        $verified = 0;
        $lost = 0;
        $siteHost = $this->normalizeDomain($site->url);

        foreach ($backlinks as $backlink) {
            if ($verified + $lost > 0) {
                usleep(self::RATE_LIMIT_MS * 1000);
            }

            try {
                $response = Http::withOptions(['verify' => false])
                    ->withUserAgent(self::USER_AGENT)
                    ->timeout(self::TIMEOUT)
                    ->get($backlink->source_url);

                if (! $response->successful()) {
                    $backlink->update([
                        'is_alive' => false,
                        'lost_at' => now()->toDateString(),
                        'last_verified_at' => now(),
                    ]);
                    $lost++;

                    continue;
                }

                $html = $response->body();
                $hasLink = $this->pageContainsLinkTo($html, $siteHost);

                if ($hasLink) {
                    $backlink->update([
                        'is_alive' => true,
                        'last_verified_at' => now(),
                        'last_seen_at' => now()->toDateString(),
                    ]);
                    $verified++;
                } else {
                    $backlink->update([
                        'is_alive' => false,
                        'lost_at' => now()->toDateString(),
                        'last_verified_at' => now(),
                    ]);
                    $lost++;
                }
            } catch (\Throwable) {
                // Network error — don't mark as lost, just skip
                continue;
            }
        }

        return ['verified' => $verified, 'lost' => $lost];
    }

    /**
     * Parse an HTML page and extract all links pointing to our site.
     */
    private function parsePage(string $html, string $pageUrl, string $siteHost, string $siteUrl): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        // Page title
        $titleNodes = $xpath->query('//title');
        $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : null;

        // All links on the page
        $allLinks = $xpath->query('//a[@href]');
        $outboundLinks = 0;
        $linksToSite = [];

        for ($i = 0; $i < $allLinks->length; $i++) {
            $a = $allLinks->item($i);
            $href = trim($a->getAttribute('href'));

            if ($href === '' || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:')) {
                continue;
            }

            // Resolve relative URLs
            if (! str_starts_with($href, 'http://') && ! str_starts_with($href, 'https://')) {
                $parsed = parse_url($pageUrl);
                $scheme = $parsed['scheme'] ?? 'https';
                $host = $parsed['host'] ?? '';
                $href = str_starts_with($href, '/')
                    ? "{$scheme}://{$host}{$href}"
                    : "{$scheme}://{$host}/{$href}";
            }

            $linkHost = $this->normalizeDomain($href);

            // Count outbound links (not internal)
            $pageHost = $this->normalizeDomain($pageUrl);
            if ($linkHost !== $pageHost) {
                $outboundLinks++;
            }

            // Check if this link points to our site
            if ($linkHost === $siteHost || str_ends_with($linkHost, ".{$siteHost}")) {
                $anchorText = trim($a->textContent);
                $rel = strtolower($a->getAttribute('rel'));
                $isNofollow = str_contains($rel, 'nofollow') || str_contains($rel, 'ugc') || str_contains($rel, 'sponsored');

                // Detect link position
                $position = $this->detectLinkPosition($a, $xpath);

                // Extract surrounding context (text around the link)
                $context = $this->extractLinkContext($a);

                $linksToSite[] = [
                    'href' => $href,
                    'anchor_text' => $anchorText,
                    'is_nofollow' => $isNofollow,
                    'position' => $position,
                    'context' => $context,
                ];
            }
        }

        // Page quality analysis
        $bodyText = '';
        $bodyNodes = $xpath->query('//body');
        if ($bodyNodes->length > 0) {
            $bodyText = $bodyNodes->item(0)->textContent;
        }

        $wordCount = str_word_count($bodyText);

        return [
            'title' => $title,
            'links_to_site' => $linksToSite,
            'quality' => [
                'outbound_links' => $outboundLinks,
                'word_count' => $wordCount,
                'is_thin' => $wordCount < 200,
                'has_excessive_links' => $outboundLinks > 100,
            ],
        ];
    }

    /**
     * Quick check if a page contains any link to our domain.
     */
    private function pageContainsLinkTo(string $html, string $siteHost): bool
    {
        // Quick string check first (faster than DOM parsing)
        if (! str_contains(strtolower($html), strtolower($siteHost))) {
            return false;
        }

        // DOM parse to confirm it's actually in an <a> tag
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);
        $links = $xpath->query('//a[@href]');

        for ($i = 0; $i < $links->length; $i++) {
            $href = $links->item($i)->getAttribute('href');
            $linkHost = $this->normalizeDomain($href);

            if ($linkHost === $siteHost || str_ends_with($linkHost, ".{$siteHost}")) {
                return true;
            }
        }

        return false;
    }

    /**
     * Detect where in the page the link appears.
     */
    private function detectLinkPosition(\DOMElement $link, DOMXPath $xpath): string
    {
        $node = $link;

        while ($node->parentNode) {
            $node = $node->parentNode;
            $tag = strtolower($node->nodeName ?? '');
            $classes = strtolower($node instanceof \DOMElement ? ($node->getAttribute('class') ?? '') : '');
            $id = strtolower($node instanceof \DOMElement ? ($node->getAttribute('id') ?? '') : '');
            $role = strtolower($node instanceof \DOMElement ? ($node->getAttribute('role') ?? '') : '');

            if ($tag === 'nav' || $role === 'navigation' || str_contains($classes, 'nav') || str_contains($id, 'nav')) {
                return 'navigation';
            }

            if ($tag === 'header' || str_contains($classes, 'header') || str_contains($id, 'header')) {
                return 'header';
            }

            if ($tag === 'footer' || str_contains($classes, 'footer') || str_contains($id, 'footer')) {
                return 'footer';
            }

            if (str_contains($classes, 'sidebar') || str_contains($id, 'sidebar') || str_contains($classes, 'widget')) {
                return 'sidebar';
            }

            if ($tag === 'article' || $tag === 'main' || str_contains($classes, 'content') || str_contains($classes, 'entry') || str_contains($id, 'content')) {
                return 'content';
            }

            if (str_contains($classes, 'comment') || str_contains($id, 'comment')) {
                return 'comment';
            }
        }

        return 'content';
    }

    /**
     * Extract text surrounding the link for context.
     */
    private function extractLinkContext(\DOMElement $link): string
    {
        $parent = $link->parentNode;
        if (! $parent) {
            return '';
        }

        $text = trim($parent->textContent);

        return mb_strlen($text) > 300 ? mb_substr($text, 0, 300).'...' : $text;
    }

    /**
     * Discover backlinks via Google Search: search for pages mentioning our domain.
     *
     * Uses Google Custom Search or direct scraping to find:
     * "domain.com" -site:domain.com
     */
    public function discoverViaSearch(Site $site, int $maxResults = 50): array
    {
        $siteHost = $this->normalizeDomain($site->url);
        $discoveredUrls = [];

        // Use Google Autocomplete/Suggest as a lightweight discovery
        // Search for variations of the domain
        $searchQueries = [
            "\"{$siteHost}\"",
            "link:{$siteHost}",
        ];

        foreach ($searchQueries as $query) {
            try {
                // Use a simple HTTP request to Google's search
                $response = Http::withOptions(['verify' => false])
                    ->withUserAgent('Mozilla/5.0 (compatible; SimpleAd SEO/1.0)')
                    ->timeout(10)
                    ->get('https://www.google.com/search', [
                        'q' => $query.' -site:'.$siteHost,
                        'num' => min($maxResults, 20),
                    ]);

                if (! $response->successful()) {
                    continue;
                }

                $html = $response->body();

                // Extract URLs from search results
                preg_match_all('/https?:\/\/[^\s"<>]+/i', $html, $matches);
                $urls = $matches[0] ?? [];

                foreach ($urls as $url) {
                    $urlHost = $this->normalizeDomain($url);

                    // Skip Google's own URLs, our site, and common non-content URLs
                    if ($urlHost === $siteHost ||
                        str_contains($urlHost, 'google.') ||
                        str_contains($urlHost, 'googleapis.') ||
                        str_contains($urlHost, 'gstatic.') ||
                        str_contains($urlHost, 'youtube.') ||
                        str_contains($url, '/search?') ||
                        str_contains($url, 'webcache.')) {
                        continue;
                    }

                    $discoveredUrls[$url] = true;
                }
            } catch (\Throwable $e) {
                Log::debug("Google search discovery failed for {$siteHost}: {$e->getMessage()}");
            }
        }

        return array_keys($discoveredUrls);
    }

    private function normalizeDomain(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST) ?: '';

        return preg_replace('/^www\./', '', strtolower($host)) ?? strtolower($host);
    }
}
