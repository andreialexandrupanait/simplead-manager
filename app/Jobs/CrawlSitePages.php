<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SeoAuditStatus;
use App\Models\SeoAudit;
use App\Models\SeoImage;
use App\Models\SeoLink;
use App\Models\SeoPage;
use App\Models\Site;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
use App\Services\SeoAudit\UrlNormalizerService;
use DOMDocument;
use DOMElement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class CrawlSitePages implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    // P2-20: right-sized from config so the timeout can bound the full crawl
    // budget (crawl loop + broken-link/image checks) rather than SIGKILLing
    // mid-crawl. Kept as a property (default) with a config override applied in
    // the constructor.
    public int $timeout = 1500;

    public int $uniqueFor = 1680;

    public array $backoff = [60, 120];

    private const SKIP_EXTENSIONS = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'zip', 'rar', 'jpg', 'jpeg', 'png', 'gif', 'svg', 'webp', 'ico', 'mp3', 'mp4', 'avi', 'mov', 'woff', 'woff2', 'ttf', 'eot', 'css', 'js', 'xml', 'json', 'map'];

    private const SKIP_PATHS = ['/wp-admin', '/wp-login.php', '/wp-json', '/feed', '/xmlrpc.php', '/wp-cron.php', '/wp-content/uploads'];

    public function __construct(public Site $site, public SeoAudit $audit)
    {
        $this->onQueue('performance');
        $this->timeout = (int) config('seo.crawler.job_timeout_seconds', 1500);
        $this->uniqueFor = $this->timeout + 180;
    }

    public function uniqueId(): string
    {
        return 'seo-crawl-'.$this->site->id;
    }

    public function handle(): void
    {
        $trackerId = 'seo-audit-'.$this->site->id;
        $this->audit->markAs(SeoAuditStatus::Crawling);

        // P2-20: make the crawl idempotent. A retry (tries=2) re-runs handle()
        // and would otherwise re-insert every page/link/image for this audit,
        // duplicating rows. Clear this audit's prior crawl data first so each
        // run starts clean.
        $this->audit->links()->delete();
        $this->audit->images()->delete();
        $this->audit->pages()->delete();

        $siteUrl = rtrim($this->site->url, '/');
        $baseDomain = UrlNormalizerService::extractHost($siteUrl) ?? parse_url($siteUrl, PHP_URL_HOST);
        $monitor = $this->site->seoMonitor;
        $maxPages = $monitor?->max_pages ?? (int) config('seo.crawler.default_max_pages');
        $delayMs = (int) config('seo.crawler.delay_ms');
        $userAgent = config('seo.crawler.user_agent');
        $pageTimeout = (int) config('seo.crawler.timeout_per_page');

        $visited = [];
        $queue = [$siteUrl.'/'];
        $crawled = 0;
        $inboundCounts = [];

        // P2-20: bound the crawl loop to a runtime budget that finishes well
        // before the job timeout, leaving room for the post-crawl broken-link
        // and broken-image HEAD checks. Without this the crawl (max_pages ×
        // per-page timeout + delay) could exceed $timeout and be SIGKILLed
        // mid-run, leaving a half-written audit.
        $crawlDeadline = microtime(true) + (int) config('seo.crawler.max_runtime_seconds', 1080);

        // Fetch sitemap
        JobTracker::progress($trackerId, 2, 'Fetching sitemap...');
        $sitemapUrls = $this->fetchSitemap($siteUrl, $monitor?->sitemap_url, $userAgent, $pageTimeout);

        // Fetch robots.txt
        $robotsData = $this->fetchRobotsTxt($siteUrl, $userAgent, $pageTimeout);
        $this->audit->update(['robots_txt_data' => $robotsData]);

        JobTracker::progress($trackerId, 5, 'Starting crawl...');

        while (! empty($queue) && $crawled < $maxPages && microtime(true) < $crawlDeadline) {
            $url = array_shift($queue);
            $urlHash = UrlNormalizerService::hash($url);

            if (isset($visited[$urlHash])) {
                continue;
            }
            $visited[$urlHash] = true;
            $crawled++;

            try {
                $startTime = microtime(true);
                $response = Http::timeout($pageTimeout)
                    ->withUserAgent($userAgent)
                    ->withoutVerifying()
                    ->withoutRedirecting()
                    ->get($url);

                $ttfb = microtime(true) - $startTime;
                $statusCode = $response->status();
                $contentType = $response->header('Content-Type') ?? '';
                $mimeType = explode(';', $contentType)[0] ?? '';

                // Handle redirects
                $redirectTarget = null;
                $redirectChainLength = 0;
                if ($statusCode >= 300 && $statusCode < 400) {
                    $redirectTarget = $response->header('Location');
                    $redirectChainLength = 1;
                    // Follow redirect and add to queue
                    if ($redirectTarget) {
                        $resolvedRedirect = $this->resolveUrl($redirectTarget, $url);
                        if (UrlNormalizerService::isSameDomain($resolvedRedirect, $baseDomain)) {
                            $queue[] = $resolvedRedirect;
                        }
                    }

                    SeoPage::create([
                        'seo_audit_id' => $this->audit->id,
                        'site_id' => $this->site->id,
                        'url' => mb_substr($url, 0, 2048),
                        'url_hash' => $urlHash,
                        'status_code' => $statusCode,
                        'depth' => $this->calculateDepth($url, $siteUrl),
                        'content_type' => mb_substr($mimeType, 0, 100),
                        'redirect_target' => $redirectTarget ? mb_substr($redirectTarget, 0, 2048) : null,
                        'redirect_chain_length' => $redirectChainLength,
                        'ttfb_seconds' => round($ttfb, 3),
                        'is_indexable' => false,
                    ]);

                    $this->updateProgress($trackerId, $crawled, $maxPages);
                    usleep($delayMs * 1000);

                    continue;
                }

                // Skip non-HTML
                if ($statusCode === 200 && ! str_contains($mimeType, 'text/html')) {
                    SeoPage::create([
                        'seo_audit_id' => $this->audit->id,
                        'site_id' => $this->site->id,
                        'url' => mb_substr($url, 0, 2048),
                        'url_hash' => $urlHash,
                        'status_code' => $statusCode,
                        'content_type' => mb_substr($mimeType, 0, 100),
                        'is_indexable' => false,
                    ]);
                    $this->updateProgress($trackerId, $crawled, $maxPages);
                    usleep($delayMs * 1000);

                    continue;
                }

                // Parse HTML
                $body = $response->body();
                $pageData = $this->parseHtml($body, $url, $baseDomain);

                // Determine indexability
                $isIndexable = $statusCode === 200;
                $metaRobots = $pageData['meta_robots'];
                if ($metaRobots && str_contains(strtolower($metaRobots), 'noindex')) {
                    $isIndexable = false;
                }
                $xRobots = $response->header('X-Robots-Tag');
                if ($xRobots && str_contains(strtolower($xRobots), 'noindex')) {
                    $isIndexable = false;
                }

                $page = SeoPage::create([
                    'seo_audit_id' => $this->audit->id,
                    'site_id' => $this->site->id,
                    'url' => mb_substr($url, 0, 2048),
                    'url_hash' => $urlHash,
                    'status_code' => $statusCode,
                    'depth' => $this->calculateDepth($url, $siteUrl),
                    'content_type' => mb_substr($mimeType, 0, 100),
                    'title' => $pageData['title'] ? mb_substr($pageData['title'], 0, 1000) : null,
                    'title_length' => $pageData['title_length'],
                    'meta_description' => $pageData['meta_description'],
                    'meta_description_length' => $pageData['meta_description_length'],
                    'h1_tags' => $pageData['h1_tags'] ?: null,
                    'heading_structure' => $pageData['heading_structure'],
                    'word_count' => $pageData['word_count'],
                    'image_count' => $pageData['image_count'],
                    'images_without_alt' => $pageData['images_without_alt'],
                    'canonical_url' => $pageData['canonical_url'] ? mb_substr($pageData['canonical_url'], 0, 2048) : null,
                    'is_self_canonical' => $pageData['is_self_canonical'],
                    'meta_robots' => $metaRobots,
                    'is_indexable' => $isIndexable,
                    'in_sitemap' => isset($sitemapUrls[UrlNormalizerService::hash($url)]),
                    'blocked_by_robots' => $this->isBlockedByRobots($url, $robotsData),
                    'internal_link_count' => count($pageData['internal_links']),
                    'external_link_count' => count($pageData['external_links']),
                    'page_size_bytes' => strlen($body),
                    'ttfb_seconds' => round($ttfb, 3),
                    'structured_data_types' => $pageData['structured_data_types'] ?: null,
                    'og_tags' => ! empty($pageData['og_tags']) ? $pageData['og_tags'] : null,
                    'twitter_tags' => ! empty($pageData['twitter_tags']) ? $pageData['twitter_tags'] : null,
                    'has_viewport_meta' => $pageData['has_viewport_meta'],
                    'meta' => array_filter([
                        'images_without_lazy' => $pageData['images_without_lazy'] > 0 ? $pageData['images_without_lazy'] : null,
                        'content_hash' => $pageData['content_hash'],
                        'hreflang' => ! empty($pageData['hreflang']) ? $pageData['hreflang'] : null,
                        'structured_data_raw' => ! empty($pageData['structured_data_raw']) ? $pageData['structured_data_raw'] : null,
                    ]),
                ]);

                // Store links and images
                $this->storeLinks($page, $pageData);
                $this->storeImages($page, $pageData);

                // Track inbound links
                foreach ($pageData['internal_links'] as $link) {
                    $linkHash = UrlNormalizerService::hash($link['url']);
                    $inboundCounts[$linkHash] = ($inboundCounts[$linkHash] ?? 0) + 1;
                }

                // Add discovered internal links to queue
                foreach ($pageData['internal_links'] as $link) {
                    $resolvedUrl = $this->resolveUrl($link['url'], $url);
                    if (! $this->shouldSkipUrl($resolvedUrl, $baseDomain)) {
                        $linkHash = UrlNormalizerService::hash($resolvedUrl);
                        if (! isset($visited[$linkHash])) {
                            $queue[] = $resolvedUrl;
                        }
                    }
                }

            } catch (\Throwable $e) {
                // Record failed page
                SeoPage::create([
                    'seo_audit_id' => $this->audit->id,
                    'site_id' => $this->site->id,
                    'url' => mb_substr($url, 0, 2048),
                    'url_hash' => $urlHash,
                    'is_indexable' => false,
                    'meta' => ['error' => mb_substr($e->getMessage(), 0, 500)],
                ]);
                Log::debug('SEO crawl: page failed', ['url' => $url, 'error' => $e->getMessage()]);
            }

            $this->updateProgress($trackerId, $crawled, $maxPages);
            usleep($delayMs * 1000);
        }

        // Update inbound link counts
        foreach ($inboundCounts as $urlHash => $count) {
            SeoPage::where('seo_audit_id', $this->audit->id)
                ->where('url_hash', $urlHash)
                ->update(['inbound_internal_links' => $count]);
        }

        // P3-20: the loop exits early when it hits $maxPages or the runtime
        // deadline. If URLs are still queued at that point the crawl did NOT cover
        // every discovered page — flag it so consumers don't read partial results
        // as a full-site audit.
        $this->audit->update([
            'pages_crawled' => $crawled,
            'coverage_partial' => ! empty($queue),
        ]);

        JobTracker::progress($trackerId, 56, 'Checking broken links...');
        $this->checkBrokenLinks();

        JobTracker::progress($trackerId, 58, 'Checking broken images...');
        $this->checkBrokenImages();

        JobTracker::progress($trackerId, 60, "Crawl complete. {$crawled} pages.");
    }

    public function failed(?\Throwable $e): void
    {
        $this->audit->markAs(SeoAuditStatus::Failed, $e?->getMessage());
        CircuitBreakerService::recordFailure($this->site, $e?->getMessage() ?? 'Crawl failed', CircuitBreakerService::DOMAIN_SEO);
        JobTracker::fail('seo-audit-'.$this->site->id, 'Crawl failed');
    }

    private function checkBrokenLinks(): void
    {
        $monitor = $this->site->seoMonitor;
        $maxChecks = $monitor?->max_external_link_checks
            ?? (int) config('seo.analysis.max_external_link_checks', 50);
        $userAgent = config('seo.crawler.user_agent');

        // External links: HEAD-check unique URLs
        $externalUrls = SeoLink::where('seo_audit_id', $this->audit->id)
            ->where('type', 'external')
            ->selectRaw('MIN(target_url) as target_url, target_url_hash')
            ->groupBy('target_url_hash')
            ->limit($maxChecks)
            ->get();

        foreach ($externalUrls as $link) {
            try {
                $response = Http::timeout(5)->withUserAgent($userAgent)->withoutVerifying()->head($link->target_url);
                $status = $response->status();

                if ($status === 405) {
                    $response = Http::timeout(5)->withUserAgent($userAgent)->withoutVerifying()->get($link->target_url);
                    $status = $response->status();
                }
            } catch (\Throwable) {
                $status = null;
            }

            $isBroken = $status === null || $status >= 400;
            SeoLink::where('seo_audit_id', $this->audit->id)
                ->where('target_url_hash', $link->target_url_hash)
                ->update(['status_code' => $status, 'is_broken' => $isBroken]);

            usleep(100_000);
        }

        // Internal links: cross-reference with crawled pages
        $pageStatuses = SeoPage::where('seo_audit_id', $this->audit->id)
            ->whereNotNull('status_code')
            ->pluck('status_code', 'url_hash')
            ->toArray();

        $internalLinks = SeoLink::where('seo_audit_id', $this->audit->id)
            ->where('type', 'internal')
            ->select('target_url_hash')
            ->distinct()
            ->pluck('target_url_hash');

        foreach ($internalLinks as $hash) {
            $pageStatus = $pageStatuses[$hash] ?? null;
            if ($pageStatus === null) {
                continue;
            }

            SeoLink::where('seo_audit_id', $this->audit->id)
                ->where('target_url_hash', $hash)
                ->where('type', 'internal')
                ->update([
                    'status_code' => $pageStatus,
                    'is_broken' => $pageStatus >= 400,
                ]);
        }
    }

    private function parseHtml(string $html, string $url, string $baseDomain): array
    {
        $data = [
            'title' => null, 'title_length' => null,
            'meta_description' => null, 'meta_description_length' => null,
            'meta_robots' => null, 'canonical_url' => null, 'is_self_canonical' => null,
            'h1_tags' => [], 'heading_structure' => ['h1' => 0, 'h2' => 0, 'h3' => 0, 'h4' => 0, 'h5' => 0, 'h6' => 0],
            'word_count' => 0, 'image_count' => 0, 'images_without_alt' => 0, 'images_without_lazy' => 0,
            'image_urls' => [], 'internal_links' => [], 'external_links' => [],
            'structured_data_types' => [], 'og_tags' => [], 'twitter_tags' => [],
            'has_viewport_meta' => false,
            'content_hash' => null, 'hreflang' => [], 'structured_data_raw' => [],
        ];

        libxml_use_internal_errors(true);
        $doc = new DOMDocument;
        $doc->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        // Title
        $titles = $doc->getElementsByTagName('title');
        if ($titles->length > 0) {
            $data['title'] = trim($titles->item(0)->textContent);
            $data['title_length'] = mb_strlen($data['title']);
        }

        // Meta tags
        foreach ($doc->getElementsByTagName('meta') as $meta) {
            if (! $meta instanceof DOMElement) {
                continue;
            }
            $name = strtolower($meta->getAttribute('name'));
            $property = strtolower($meta->getAttribute('property'));
            $content = $meta->getAttribute('content');

            if ($name === 'description') {
                $data['meta_description'] = $content;
                $data['meta_description_length'] = mb_strlen($content);
            } elseif ($name === 'robots') {
                $data['meta_robots'] = $content;
            } elseif ($name === 'viewport') {
                $data['has_viewport_meta'] = true;
            }

            if (str_starts_with($property, 'og:')) {
                $data['og_tags'][$property] = $content;
            }
            if (str_starts_with($name, 'twitter:') || str_starts_with($property, 'twitter:')) {
                $data['twitter_tags'][$name ?: $property] = $content;
            }
        }

        // Canonical + hreflang
        foreach ($doc->getElementsByTagName('link') as $link) {
            if (! ($link instanceof DOMElement)) {
                continue;
            }
            $rel = strtolower($link->getAttribute('rel'));
            if ($rel === 'canonical' && ! $data['canonical_url']) {
                $data['canonical_url'] = $link->getAttribute('href');
                $data['is_self_canonical'] = UrlNormalizerService::areEqual($data['canonical_url'], $url);
            }
            if ($rel === 'alternate' && $link->getAttribute('hreflang')) {
                $data['hreflang'][] = [
                    'lang' => $link->getAttribute('hreflang'),
                    'href' => $link->getAttribute('href'),
                ];
            }
        }

        // Headings
        for ($level = 1; $level <= 6; $level++) {
            $tag = 'h'.$level;
            $els = $doc->getElementsByTagName($tag);
            $data['heading_structure'][$tag] = $els->length;
            if ($level === 1) {
                for ($i = 0; $i < $els->length; $i++) {
                    $text = trim($els->item($i)->textContent);
                    if ($text !== '') {
                        $data['h1_tags'][] = $text;
                    }
                }
            }
        }

        // Images
        $data['images_without_lazy'] = 0;
        $imgs = $doc->getElementsByTagName('img');
        $data['image_count'] = $imgs->length;
        foreach ($imgs as $img) {
            if ($img instanceof DOMElement) {
                $altText = trim($img->getAttribute('alt'));
                $hasAlt = $altText !== '';
                $hasLazy = $img->getAttribute('loading') === 'lazy' || (bool) $img->getAttribute('data-lazy') || (bool) $img->getAttribute('data-src');

                if (! $hasAlt) {
                    $data['images_without_alt']++;
                }
                if (! $hasLazy) {
                    $data['images_without_lazy']++;
                }

                $src = $img->getAttribute('src') ?: $img->getAttribute('data-src') ?: $img->getAttribute('data-lazy-src');
                if ($src && ! str_starts_with($src, 'data:')) {
                    $resolvedSrc = $this->resolveUrl($src, $url);
                    $data['image_urls'][] = [
                        'url' => $resolvedSrc,
                        'alt' => $altText,
                        'has_alt' => $hasAlt,
                        'has_lazy' => $hasLazy,
                    ];
                }
            }
        }

        // Links
        foreach ($doc->getElementsByTagName('a') as $a) {
            if (! $a instanceof DOMElement) {
                continue;
            }
            $href = $a->getAttribute('href');
            if (empty($href) || str_starts_with($href, '#') || str_starts_with($href, 'javascript:') || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:')) {
                continue;
            }

            $rel = strtolower($a->getAttribute('rel'));
            $anchor = mb_substr(trim($a->textContent), 0, 500);
            $resolved = $this->resolveUrl($href, $url);

            if (UrlNormalizerService::isSameDomain($resolved, $baseDomain)) {
                $data['internal_links'][] = ['url' => $resolved, 'rel' => $rel, 'anchor_text' => $anchor];
            } else {
                $data['external_links'][] = ['url' => $resolved, 'rel' => $rel, 'anchor_text' => $anchor];
            }
        }

        // Word count + content hash for duplicate detection
        $bodyTag = $doc->getElementsByTagName('body');
        if ($bodyTag->length > 0) {
            $text = trim(preg_replace('/\s+/', ' ', $bodyTag->item(0)->textContent) ?? '');
            $data['word_count'] = str_word_count($text);
            if (mb_strlen($text) > 100) {
                $data['content_hash'] = md5($text);
            }
        }

        // Structured data (JSON-LD)
        foreach ($doc->getElementsByTagName('script') as $script) {
            if ($script instanceof DOMElement && strtolower($script->getAttribute('type')) === 'application/ld+json') {
                $raw = trim($script->textContent);
                $json = json_decode($raw, true);
                if (is_array($json)) {
                    $data['structured_data_raw'][] = $json;
                    if (isset($json['@type'])) {
                        $types = is_array($json['@type']) ? $json['@type'] : [$json['@type']];
                        array_push($data['structured_data_types'], ...$types);
                    }
                    if (isset($json['@graph'])) {
                        foreach ($json['@graph'] as $item) {
                            if (isset($item['@type'])) {
                                $types = is_array($item['@type']) ? $item['@type'] : [$item['@type']];
                                array_push($data['structured_data_types'], ...$types);
                            }
                        }
                    }
                } else {
                    $data['structured_data_raw'][] = ['_invalid' => true, '_error' => json_last_error_msg()];
                }
            }
        }
        $data['structured_data_types'] = array_values(array_unique($data['structured_data_types']));

        return $data;
    }

    private function storeLinks(SeoPage $page, array $pageData): void
    {
        $links = [];
        foreach ($pageData['internal_links'] as $link) {
            $links[] = [
                'seo_audit_id' => $this->audit->id, 'seo_page_id' => $page->id,
                'target_url' => mb_substr($link['url'], 0, 2048),
                'target_url_hash' => UrlNormalizerService::hash($link['url']),
                'type' => 'internal', 'rel' => $link['rel'] ?: null,
                'anchor_text' => $link['anchor_text'] ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach ($pageData['external_links'] as $link) {
            $links[] = [
                'seo_audit_id' => $this->audit->id, 'seo_page_id' => $page->id,
                'target_url' => mb_substr($link['url'], 0, 2048),
                'target_url_hash' => UrlNormalizerService::hash($link['url']),
                'type' => 'external', 'rel' => $link['rel'] ?: null,
                'anchor_text' => $link['anchor_text'] ?: null,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        foreach (array_chunk($links, 100) as $chunk) {
            SeoLink::insert($chunk);
        }
    }

    private function storeImages(SeoPage $page, array $pageData): void
    {
        $images = [];
        foreach ($pageData['image_urls'] as $img) {
            $images[] = [
                'seo_audit_id' => $this->audit->id,
                'seo_page_id' => $page->id,
                'image_url' => mb_substr($img['url'], 0, 2048),
                'image_url_hash' => UrlNormalizerService::hash($img['url']),
                'alt_text' => $img['alt'] ? mb_substr($img['alt'], 0, 1000) : null,
                'has_alt' => $img['has_alt'],
                'has_lazy_loading' => $img['has_lazy'],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($images, 100) as $chunk) {
            SeoImage::insert($chunk);
        }
    }

    private function checkBrokenImages(): void
    {
        $maxChecks = (int) config('seo.analysis.max_image_checks', 100);
        $userAgent = config('seo.crawler.user_agent');

        $imageUrls = SeoImage::where('seo_audit_id', $this->audit->id)
            ->selectRaw('MIN(image_url) as image_url, image_url_hash')
            ->groupBy('image_url_hash')
            ->limit($maxChecks)
            ->get();

        foreach ($imageUrls as $image) {
            try {
                $response = Http::timeout(5)
                    ->withUserAgent($userAgent)
                    ->withoutVerifying()
                    ->head($image->image_url);
                $status = $response->status();
                $contentType = $response->header('Content-Type') ?? '';
                $contentLength = (int) ($response->header('Content-Length') ?? 0);
            } catch (\Throwable) {
                $status = null;
                $contentType = '';
                $contentLength = 0;
            }

            $isBroken = $status === null || $status >= 400;
            SeoImage::where('seo_audit_id', $this->audit->id)
                ->where('image_url_hash', $image->image_url_hash)
                ->update([
                    'status_code' => $status,
                    'is_broken' => $isBroken,
                    'content_type' => $contentType ? mb_substr(explode(';', $contentType)[0], 0, 100) : null,
                    'file_size_bytes' => $contentLength > 0 ? $contentLength : null,
                ]);

            usleep(50_000);
        }
    }

    private function resolveUrl(string $href, string $baseUrl): string
    {
        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return $href;
        }
        $parsed = parse_url($baseUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';
        if (str_starts_with($href, '//')) {
            return $scheme.':'.$href;
        }
        if (str_starts_with($href, '/')) {
            return $scheme.'://'.$host.$href;
        }
        $basePath = $parsed['path'] ?? '/';
        $baseDir = substr($basePath, 0, (int) strrpos($basePath, '/') + 1);

        return $scheme.'://'.$host.$baseDir.$href;
    }

    private function shouldSkipUrl(string $url, string $baseDomain): bool
    {
        if (! UrlNormalizerService::isSameDomain($url, $baseDomain)) {
            return true;
        }
        $path = parse_url($url, PHP_URL_PATH) ?? '';
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, self::SKIP_EXTENSIONS, true)) {
            return true;
        }
        foreach (self::SKIP_PATHS as $skip) {
            if (str_starts_with($path, $skip)) {
                return true;
            }
        }

        return false;
    }

    private function calculateDepth(string $url, string $siteUrl): int
    {
        $path = trim(parse_url($url, PHP_URL_PATH) ?? '', '/');
        if ($path === '') {
            return 0;
        }

        return count(explode('/', $path));
    }

    private function updateProgress(string $trackerId, int $crawled, int $maxPages): void
    {
        $pct = $maxPages > 0 ? min(55, (int) round(($crawled / $maxPages) * 55) + 5) : 5;
        $this->audit->update(['pages_crawled' => $crawled]);
        JobTracker::progress($trackerId, $pct, "Crawling... {$crawled}/{$maxPages} pages");
    }

    private function fetchSitemap(string $siteUrl, ?string $configuredUrl, string $userAgent, int $timeout): array
    {
        $urls = [];
        $sitemapUrl = $configuredUrl ?: $siteUrl.'/sitemap.xml';

        try {
            $response = Http::timeout($timeout)->withUserAgent($userAgent)->withoutVerifying()->get($sitemapUrl);
            if (! $response->successful()) {
                $this->audit->update(['data' => array_merge($this->audit->data ?? [], ['sitemap' => ['found' => false, 'url' => $sitemapUrl]])]);

                return [];
            }

            $xml = @simplexml_load_string($response->body());
            if (! $xml) {
                return [];
            }

            // Handle sitemap index
            if (isset($xml->sitemap)) {
                foreach ($xml->sitemap as $entry) {
                    $subUrl = (string) $entry->loc;
                    try {
                        $subResponse = Http::timeout($timeout)->withUserAgent($userAgent)->withoutVerifying()->get($subUrl);
                        if ($subResponse->successful()) {
                            $subXml = @simplexml_load_string($subResponse->body());
                            if ($subXml && isset($subXml->url)) {
                                foreach ($subXml->url as $u) {
                                    $loc = (string) $u->loc;
                                    $urls[UrlNormalizerService::hash($loc)] = $loc;
                                }
                            }
                        }
                    } catch (\Throwable) {
                    }
                }
            }

            // Handle regular sitemap
            if (isset($xml->url)) {
                foreach ($xml->url as $u) {
                    $loc = (string) $u->loc;
                    $urls[UrlNormalizerService::hash($loc)] = $loc;
                }
            }

            $this->audit->update([
                'data' => array_merge($this->audit->data ?? [], [
                    'sitemap' => ['found' => true, 'url' => $sitemapUrl, 'url_count' => count($urls)],
                ]),
                'sitemap_urls_count' => count($urls),
            ]);
        } catch (\Throwable $e) {
            Log::debug('SEO: sitemap fetch failed', ['url' => $sitemapUrl, 'error' => $e->getMessage()]);
        }

        return $urls;
    }

    private function fetchRobotsTxt(string $siteUrl, string $userAgent, int $timeout): array
    {
        try {
            $response = Http::timeout($timeout)->withUserAgent($userAgent)->withoutVerifying()->get($siteUrl.'/robots.txt');
            if (! $response->successful()) {
                return ['exists' => false, 'disallow_rules' => [], 'sitemap_urls' => []];
            }

            $body = $response->body();
            $disallowRules = [];
            $sitemapUrls = [];
            $currentAgent = '';

            foreach (explode("\n", $body) as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (preg_match('/^User-agent:\s*(.+)/i', $line, $m)) {
                    $currentAgent = strtolower(trim($m[1]));
                } elseif (preg_match('/^Disallow:\s*(.+)/i', $line, $m)) {
                    if ($currentAgent === '*' || $currentAgent === '') {
                        $disallowRules[] = trim($m[1]);
                    }
                } elseif (preg_match('/^Sitemap:\s*(.+)/i', $line, $m)) {
                    $sitemapUrls[] = trim($m[1]);
                }
            }

            return ['exists' => true, 'disallow_rules' => $disallowRules, 'sitemap_urls' => $sitemapUrls];
        } catch (\Throwable) {
            return ['exists' => false, 'disallow_rules' => [], 'sitemap_urls' => []];
        }
    }

    private function isBlockedByRobots(string $url, array $robotsData): bool
    {
        if (! ($robotsData['exists'] ?? false)) {
            return false;
        }

        $path = parse_url($url, PHP_URL_PATH) ?? '/';
        foreach ($robotsData['disallow_rules'] ?? [] as $rule) {
            if ($rule === '/') {
                return true;
            }
            if (str_starts_with($path, $rule)) {
                return true;
            }
        }

        return false;
    }
}
