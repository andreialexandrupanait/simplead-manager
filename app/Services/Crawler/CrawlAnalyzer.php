<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Models\CrawledPage;
use App\Models\SiteCrawl;
use Illuminate\Support\Collection;

class CrawlAnalyzer
{
    private const TITLE_MIN_LENGTH = 30;

    private const TITLE_MAX_LENGTH = 60;

    private const DESC_MIN_LENGTH = 120;

    private const DESC_MAX_LENGTH = 160;

    private const THIN_CONTENT_THRESHOLD = 300;

    /**
     * Run post-crawl analysis, write issues back to CrawledPage rows,
     * and return a summary statistics array.
     *
     * @return array<string, mixed>
     */
    public function analyze(SiteCrawl $crawl): array
    {
        // Load all pages for this crawl into memory for cross-page analysis.
        // We process in chunks for DB writes but need the full set for lookups.
        /** @var Collection<int, CrawledPage> $allPages */
        $allPages = CrawledPage::where('site_crawl_id', $crawl->id)->get();

        if ($allPages->isEmpty()) {
            return $this->emptySummary();
        }

        // Build lookup maps
        /** @var array<string, CrawledPage> $urlMap url => page */
        $urlMap = $allPages->keyBy('url')->all();

        // --- Aggregate counts for cross-page checks ---

        // Titles frequency map
        /** @var array<string, list<int>> $titleMap title => [page_id, ...] */
        $titleMap = [];
        foreach ($allPages as $page) {
            if ($page->title !== null && $page->title !== '') {
                $titleMap[$page->title][] = $page->id;
            }
        }
        $duplicateTitleIds = $this->duplicateIds($titleMap);

        // Meta description frequency map
        /** @var array<string, list<int>> $descMap */
        $descMap = [];
        foreach ($allPages as $page) {
            if ($page->meta_description !== null && $page->meta_description !== '') {
                $descMap[$page->meta_description][] = $page->id;
            }
        }
        $duplicateDescIds = $this->duplicateIds($descMap);

        // Build set of URLs that are linked to from other pages (for orphan detection)
        /** @var array<string, true> $linkedUrls */
        $linkedUrls = [];
        foreach ($allPages as $page) {
            foreach ($page->internal_links ?? [] as $link) {
                $linkUrl = $link['url'] ?? '';
                if ($linkUrl !== '') {
                    $linkedUrls[$linkUrl] = true;
                }
            }
        }

        // Collect status codes of all crawled URLs for canonical checks
        /** @var array<string, int|null> $urlStatusMap */
        $urlStatusMap = $allPages->pluck('status_code', 'url')->all();

        // --- Collect issues per page ---

        /** @var array<int, list<array{type: string, severity: string, message: string}>> $issuesMap */
        $issuesMap = [];

        // Counters for summary
        $totalPages = $allPages->count();
        $htmlPages = 0;
        $status2xx = 0;
        $status3xx = 0;
        $status4xx = 0;
        $status5xx = 0;
        $totalResponseTime = 0;
        $totalWordCount = 0;
        $missingTitles = 0;
        $missingDescriptions = 0;
        $missingH1 = 0;
        $multipleH1 = 0;
        $thinContent = 0;
        $brokenLinks = 0;
        $orphanPages = 0;
        $noindexPages = 0;
        $duplicateTitlesCount = count($duplicateTitleIds);
        $duplicateDescriptionsCount = count($duplicateDescIds);

        $siteHomepageUrl = null;

        foreach ($allPages as $page) {
            $issues = [];
            $isHtml = $page->content_type !== null && str_contains(strtolower($page->content_type), 'text/html');
            $isSuccess = $page->status_code !== null && $page->status_code >= 200 && $page->status_code < 300;
            $isRedirect = $page->status_code !== null && $page->status_code >= 300 && $page->status_code < 400;
            $isError = $page->status_code !== null && $page->status_code >= 400;

            // Track the homepage (depth 0 or the first page)
            if ($siteHomepageUrl === null) {
                $siteHomepageUrl = $page->url;
            }

            // Status code bucketing
            if ($page->status_code === null || $page->status_code === 0) {
                $status4xx++; // count failed requests as errors
                $brokenLinks++;
            } elseif ($page->status_code < 300) {
                $status2xx++;
            } elseif ($page->status_code < 400) {
                $status3xx++;
            } elseif ($page->status_code < 500) {
                $status4xx++;
                $brokenLinks++;
            } else {
                $status5xx++;
                $brokenLinks++;
            }

            if ($page->response_time_ms !== null) {
                $totalResponseTime += $page->response_time_ms;
            }

            if (! $isHtml || ! $isSuccess) {
                // Non-HTML / non-2xx pages: carry over existing issues
                $issuesMap[$page->id] = array_merge($page->issues ?? [], $issues);

                continue;
            }

            $htmlPages++;
            $totalWordCount += $page->word_count;

            // 1 & 2. Duplicate title / meta description
            if (in_array($page->id, $duplicateTitleIds, true)) {
                $issues[] = [
                    'type' => 'duplicate_title',
                    'severity' => 'high',
                    'message' => 'This page shares its title tag with one or more other pages.',
                    'recommendation' => 'Write a unique, descriptive title for this page that includes the primary keyword. Each page should have a distinct title reflecting its specific content.',
                ];
            }

            if (in_array($page->id, $duplicateDescIds, true)) {
                $issues[] = [
                    'type' => 'duplicate_meta_description',
                    'severity' => 'medium',
                    'message' => 'This page shares its meta description with one or more other pages.',
                    'recommendation' => 'Write a unique meta description (120-160 chars) that summarizes this specific page\'s content and includes a call-to-action.',
                ];
            }

            // 3. Missing title
            if ($page->title === null || $page->title === '') {
                $missingTitles++;
                $issues[] = [
                    'type' => 'missing_title',
                    'severity' => 'critical',
                    'message' => 'Page has no title tag.',
                    'recommendation' => 'Add a <title> tag in the <head> section. Use format: "Primary Keyword - Secondary Keyword | Brand Name". Keep it under 60 characters. In WordPress, set it via Yoast SEO or RankMath.',
                ];
            } else {
                if ($page->title_length < self::TITLE_MIN_LENGTH) {
                    $issues[] = [
                        'type' => 'short_title',
                        'severity' => 'medium',
                        'message' => "Title is too short ({$page->title_length} chars). Recommended minimum: ".self::TITLE_MIN_LENGTH.' chars.',
                        'recommendation' => 'Expand the title to at least 30 characters. Include the primary keyword and make it descriptive enough to entice clicks from search results.',
                    ];
                } elseif ($page->title_length > self::TITLE_MAX_LENGTH) {
                    $issues[] = [
                        'type' => 'long_title',
                        'severity' => 'low',
                        'message' => "Title is too long ({$page->title_length} chars). Recommended maximum: ".self::TITLE_MAX_LENGTH.' chars.',
                        'recommendation' => 'Shorten the title to under 60 characters. Google truncates longer titles in search results, which can reduce click-through rates.',
                    ];
                }
            }

            // 5. Missing meta description
            if ($page->meta_description === null || $page->meta_description === '') {
                $missingDescriptions++;
                $issues[] = [
                    'type' => 'missing_meta_description',
                    'severity' => 'high',
                    'message' => 'Page has no meta description.',
                    'recommendation' => 'Add a meta description tag (120-160 chars) that summarizes the page content. Include the target keyword naturally. In WordPress, use Yoast SEO or RankMath to set it.',
                ];
            } else {
                if ($page->meta_desc_length < self::DESC_MIN_LENGTH) {
                    $issues[] = [
                        'type' => 'short_meta_description',
                        'severity' => 'low',
                        'message' => "Meta description is too short ({$page->meta_desc_length} chars). Recommended minimum: ".self::DESC_MIN_LENGTH.' chars.',
                        'recommendation' => 'Expand the meta description to at least 120 characters. Use it to summarize the page content and include a compelling reason to click.',
                    ];
                } elseif ($page->meta_desc_length > self::DESC_MAX_LENGTH) {
                    $issues[] = [
                        'type' => 'long_meta_description',
                        'severity' => 'low',
                        'message' => "Meta description is too long ({$page->meta_desc_length} chars). Recommended maximum: ".self::DESC_MAX_LENGTH.' chars.',
                        'recommendation' => 'Shorten the meta description to under 160 characters. Google truncates longer descriptions, potentially cutting off important information.',
                    ];
                }
            }

            // 7. Multiple H1
            if ($page->h1_count > 1) {
                $multipleH1++;
                $issues[] = [
                    'type' => 'multiple_h1',
                    'severity' => 'medium',
                    'message' => "Page has {$page->h1_count} H1 tags. Only one H1 is recommended.",
                    'recommendation' => 'Keep only one H1 tag per page — it should be the main heading describing the page topic. Convert extra H1s to H2 or H3 tags.',
                ];
            }

            // 8. Missing H1
            if ($page->h1_count === 0) {
                $missingH1++;
                $issues[] = [
                    'type' => 'missing_h1',
                    'severity' => 'high',
                    'message' => 'Page has no H1 heading.',
                    'recommendation' => 'Add a single H1 tag containing the primary keyword for this page. The H1 should clearly describe the main topic. In WordPress, the post/page title usually generates the H1.',
                ];
            }

            // 9. Thin content
            if ($page->word_count < self::THIN_CONTENT_THRESHOLD) {
                $thinContent++;
                $issues[] = [
                    'type' => 'thin_content',
                    'severity' => 'medium',
                    'message' => "Page has only {$page->word_count} words. Recommended minimum: ".self::THIN_CONTENT_THRESHOLD.' words.',
                    'recommendation' => 'Add more substantive content — aim for at least 300 words. Cover the topic comprehensively: add explanations, examples, FAQs, or related information that adds value for users.',
                ];
            }

            // 12. Canonical issues
            if ($page->canonical_url !== null && $page->canonical_url !== '') {
                $canonicalNorm = rtrim($page->canonical_url, '/');
                $canonicalStatus = $urlStatusMap[$canonicalNorm] ?? $urlStatusMap[$page->canonical_url] ?? null;

                if ($canonicalStatus !== null && ($canonicalStatus < 200 || $canonicalStatus >= 300)) {
                    $issues[] = [
                        'type' => 'canonical_to_non_200',
                        'severity' => 'high',
                        'message' => "Canonical URL points to a page with status {$canonicalStatus}: {$page->canonical_url}",
                        'recommendation' => "Update the canonical tag to point to a valid, accessible URL (status 200). If the target page was moved, update the canonical to the new location. If it was deleted, set a self-referencing canonical instead.",
                    ];
                }
            }

            // 13. Orphan page
            $normPageUrl = rtrim($page->url, '/');
            $isHomepage = $siteHomepageUrl !== null && rtrim($siteHomepageUrl, '/') === $normPageUrl;

            if (! $isHomepage && ! isset($linkedUrls[$page->url]) && ! isset($linkedUrls[$normPageUrl])) {
                $orphanPages++;
                $issues[] = [
                    'type' => 'orphan_page',
                    'severity' => 'medium',
                    'message' => 'This page is not linked to from any other crawled page.',
                    'recommendation' => 'Add internal links to this page from relevant content pages, navigation menus, or the sitemap. Orphan pages are hard for search engines to discover and rank.',
                ];
            }

            // 14. Noindex
            $robotsMeta = strtolower($page->meta_robots ?? '');
            $xRobots = strtolower($page->x_robots_tag ?? '');
            if (str_contains($robotsMeta, 'noindex') || str_contains($xRobots, 'noindex')) {
                $noindexPages++;
                $issues[] = [
                    'type' => 'noindex',
                    'severity' => 'info',
                    'message' => 'Page is marked noindex and will not be indexed by search engines.',
                    'recommendation' => 'If this page should appear in search results, remove the noindex directive from the meta robots tag or X-Robots-Tag header. If intentional (login pages, thank-you pages), no action needed.',
                ];
            }

            // 15. Missing OG image
            if ($page->og_image === null || $page->og_image === '') {
                $issues[] = [
                    'type' => 'missing_og_image',
                    'severity' => 'low',
                    'message' => 'Page is missing an Open Graph image (og:image).',
                    'recommendation' => 'Add an og:image meta tag with a high-quality image (1200x630px recommended). This image appears when the page is shared on social media. In WordPress, set the Featured Image and use Yoast/RankMath.',
                ];
            }

            // 16. Missing OG tags entirely
            if (($page->og_title === null || $page->og_title === '') && ($page->og_description === null || $page->og_description === '')) {
                $issues[] = [
                    'type' => 'missing_og_tags',
                    'severity' => 'low',
                    'message' => 'Page has no Open Graph tags (og:title, og:description).',
                    'recommendation' => 'Add Open Graph meta tags (og:title, og:description, og:image, og:url) to control how the page appears when shared on Facebook, LinkedIn, etc. Yoast SEO and RankMath generate these automatically.',
                ];
            }

            // 17. H1 same as title
            if ($page->title && $page->h1_count === 1) {
                $h1Text = trim($page->h1_tags[0] ?? '');
                if ($h1Text !== '' && mb_strtolower($h1Text) === mb_strtolower($page->title)) {
                    $issues[] = [
                        'type' => 'h1_same_as_title',
                        'severity' => 'low',
                        'message' => 'H1 is identical to the title tag. Consider differentiating them.',
                        'recommendation' => 'Use a slightly different H1 than the title. The title targets search results (include brand, keep short), while the H1 targets on-page readers (can be more descriptive or engaging).',
                    ];
                }
            }

            // 18. Heading hierarchy broken
            if ($page->h1_count > 0 && $page->h3_count > 0 && $page->h2_count === 0) {
                $issues[] = [
                    'type' => 'heading_hierarchy_broken',
                    'severity' => 'low',
                    'message' => 'Page skips H2 headings — has H1 and H3 but no H2.',
                    'recommendation' => 'Use headings in proper order: H1 → H2 → H3. Don\'t skip levels. Convert H3 tags to H2, or add H2 sections above the H3 content to create a logical hierarchy.',
                ];
            }

            // 19. Missing canonical self-reference
            if (! $page->canonical_self_ref && $page->canonical_url === null) {
                $issues[] = [
                    'type' => 'missing_canonical_self_ref',
                    'severity' => 'low',
                    'message' => 'Page has no canonical tag. A self-referencing canonical is recommended.',
                    'recommendation' => 'Add a self-referencing canonical tag: <link rel="canonical" href="[this page URL]">. This helps search engines identify the preferred URL version and prevents duplicate content issues from URL parameters.',
                ];
            }

            // 20. Images missing dimensions
            $imgsMissingDims = 0;
            foreach ($page->images ?? [] as $img) {
                if (empty($img['width']) && empty($img['height'])) {
                    $imgsMissingDims++;
                }
            }
            if ($imgsMissingDims > 0) {
                $issues[] = [
                    'type' => 'images_missing_dimensions',
                    'severity' => 'low',
                    'message' => "{$imgsMissingDims} image(s) missing width/height attributes (causes layout shift).",
                    'recommendation' => "Add explicit width and height attributes to all <img> tags. This prevents Cumulative Layout Shift (CLS) — a Core Web Vital metric. WordPress usually adds these automatically; check your theme's image handling.",
                ];
            }

            // 21. Weak anchor text
            $weakAnchors = ['click here', 'aici', 'mai mult', 'read more', 'learn more', 'citeste', 'citește'];
            foreach ($page->internal_links ?? [] as $link) {
                $anchor = mb_strtolower(trim($link['anchor'] ?? ''));
                if ($anchor !== '' && in_array($anchor, $weakAnchors, true)) {
                    $issues[] = [
                        'type' => 'weak_anchor_text',
                        'severity' => 'info',
                        'message' => "Internal link with generic anchor text: \"{$link['anchor']}\".",
                        'recommendation' => 'Replace generic anchor text ("click here", "read more") with descriptive text that tells users and search engines what the linked page is about. Example: "read our SEO guide" instead of "click here".',
                    ];

                    break;
                }
            }

            // 22. UTM parameters in internal links
            $hasUtm = false;
            foreach ($page->internal_links ?? [] as $link) {
                if (str_contains($link['url'] ?? '', 'utm_')) {
                    $hasUtm = true;

                    break;
                }
            }
            if ($hasUtm) {
                $issues[] = [
                    'type' => 'utm_in_internal_links',
                    'severity' => 'info',
                    'message' => 'Page contains internal links with UTM tracking parameters.',
                    'recommendation' => 'Remove UTM parameters from internal links. They are meant for tracking external campaigns and pollute your analytics data when used internally. Use site search or events for internal tracking instead.',
                ];
            }

            // 23. Slow page
            if ($page->response_time_ms !== null && $page->response_time_ms > 2000) {
                $issues[] = [
                    'type' => 'slow_response',
                    'severity' => 'medium',
                    'message' => "Page response time is {$page->response_time_ms}ms (>2000ms).",
                    'recommendation' => 'Investigate server performance: enable page caching (WP Super Cache, W3 Total Cache), optimize database queries, upgrade hosting if needed. Check for heavy plugins or unoptimized images slowing the page.',
                ];
            }

            // 24. Deep page
            if ($page->depth > 5) {
                $issues[] = [
                    'type' => 'deep_page',
                    'severity' => 'low',
                    'message' => "Page is at depth {$page->depth}. Pages beyond depth 5 are harder for search engines to discover.",
                    'recommendation' => 'Restructure your site navigation to make this page reachable in fewer clicks from the homepage. Add it to a menu, sidebar, or link to it from higher-level pages. Aim for all important pages within 3 clicks.',
                ];
            }

            // 25. Structured data validation
            if (! empty($page->structured_data_types)) {
                $sdIssues = $this->validateStructuredData($page);
                $issues = array_merge($issues, $sdIssues);
            }

            // 26. Hreflang validation
            if (! empty($page->hreflang)) {
                $hlIssues = $this->validateHreflang($page, $urlStatusMap);
                $issues = array_merge($issues, $hlIssues);
            }

            $issuesMap[$page->id] = array_merge($page->issues ?? [], $issues);
        }

        // 10. Broken internal links — already counted in main loop via status codes.
        //     Add issue to each error page.
        foreach ($allPages as $page) {
            if (($page->status_code === 0 || ($page->status_code !== null && $page->status_code >= 400))
                && ! isset($issuesMap[$page->id])
            ) {
                $issuesMap[$page->id] = array_merge($page->issues ?? [], [
                    [
                        'type' => 'broken_page',
                        'severity' => 'critical',
                        'message' => "Page returned status {$page->status_code}.",
                        'recommendation' => $page->status_code >= 500
                            ? 'Server error — check server logs, fix the application error, or contact your hosting provider. Ensure the page loads without errors.'
                            : ($page->status_code === 404
                                ? 'Page not found — either restore the content, set up a 301 redirect to the most relevant existing page, or remove all internal links pointing to this URL.'
                                : ($page->status_code === 0
                                    ? 'Connection failed — the server did not respond. Check if the URL is correct, the server is running, and there are no firewall/DNS issues.'
                                    : 'Fix the HTTP error or set up a 301 redirect to the appropriate page.')),
                    ],
                ]);
            }
        }

        // 11. Redirect chains — pages that redirect to pages that also redirect
        foreach ($allPages as $page) {
            if (! $page->isRedirect() || $page->redirect_url === null) {
                continue;
            }

            $redirectTarget = $page->redirect_url;
            $targetPage = $urlMap[rtrim($redirectTarget, '/')] ?? $urlMap[$redirectTarget] ?? null;

            if ($targetPage !== null && $targetPage->isRedirect()) {
                $existing = $issuesMap[$page->id] ?? ($page->issues ?? []);
                $existing[] = [
                    'type' => 'redirect_chain',
                    'severity' => 'medium',
                    'message' => "This page is part of a redirect chain: it redirects to {$redirectTarget} which also redirects.",
                    'recommendation' => 'Update the redirect to point directly to the final destination URL, eliminating intermediate hops. Each redirect adds latency and dilutes link equity. Update internal links to point to the final URL directly.',
                ];
                $issuesMap[$page->id] = $existing;
            }
        }

        // --- Persist issues back to DB in chunks ---
        $pagesWithIssues = 0;
        $totalIssues = 0;

        CrawledPage::where('site_crawl_id', $crawl->id)->chunk(100, function ($chunk) use (&$issuesMap, &$pagesWithIssues, &$totalIssues): void {
            foreach ($chunk as $page) {
                $issues = $issuesMap[$page->id] ?? [];
                if (! empty($issues)) {
                    $page->update(['issues' => $issues]);
                    $pagesWithIssues++;
                    $totalIssues += count($issues);
                }
            }
        });

        $avgResponseTime = $totalPages > 0
            ? round($totalResponseTime / $totalPages, 2)
            : 0.0;

        $avgWordCount = $htmlPages > 0
            ? round($totalWordCount / $htmlPages, 2)
            : 0.0;

        return [
            'total_pages' => $totalPages,
            'html_pages' => $htmlPages,
            'status_2xx' => $status2xx,
            'status_3xx' => $status3xx,
            'status_4xx' => $status4xx,
            'status_5xx' => $status5xx,
            'avg_response_time' => $avgResponseTime,
            'avg_word_count' => $avgWordCount,
            'duplicate_titles' => $duplicateTitlesCount,
            'duplicate_descriptions' => $duplicateDescriptionsCount,
            'missing_titles' => $missingTitles,
            'missing_descriptions' => $missingDescriptions,
            'missing_h1' => $missingH1,
            'multiple_h1' => $multipleH1,
            'thin_content' => $thinContent,
            'broken_links' => $brokenLinks,
            'orphan_pages' => $orphanPages,
            'noindex_pages' => $noindexPages,
            'pages_with_issues' => $pagesWithIssues,
            'total_issues' => $totalIssues,
        ];
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Given a frequency map of value => [id, id, ...], return a flat list of
     * all IDs that appear in groups with more than one member.
     *
     * @param  array<string, list<int>>  $map
     * @return list<int>
     */
    private function duplicateIds(array $map): array
    {
        $ids = [];

        foreach ($map as $group) {
            if (count($group) > 1) {
                foreach ($group as $id) {
                    $ids[] = $id;
                }
            }
        }

        return $ids;
    }

    /**
     * Validate structured data on a page — check required fields per schema type.
     *
     * @return list<array{type: string, severity: string, message: string, recommendation: string}>
     */
    private function validateStructuredData(CrawledPage $page): array
    {
        $issues = [];
        $requiredFields = [
            'Article' => ['headline', 'author', 'datePublished'],
            'NewsArticle' => ['headline', 'author', 'datePublished'],
            'BlogPosting' => ['headline', 'author', 'datePublished'],
            'Product' => ['name', 'image'],
            'LocalBusiness' => ['name', 'address'],
            'Organization' => ['name', 'url'],
            'Person' => ['name'],
            'BreadcrumbList' => ['itemListElement'],
            'FAQPage' => ['mainEntity'],
            'HowTo' => ['name', 'step'],
            'Recipe' => ['name', 'recipeIngredient'],
            'Event' => ['name', 'startDate', 'location'],
            'Review' => ['itemReviewed', 'reviewRating'],
        ];

        foreach ($page->structured_data_types ?? [] as $schemaType) {
            if (isset($requiredFields[$schemaType])) {
                $missing = implode(', ', $requiredFields[$schemaType]);
                $issues[] = [
                    'type' => 'structured_data_incomplete',
                    'severity' => 'medium',
                    'message' => "{$schemaType} schema detected — verify required fields: {$missing}.",
                    'recommendation' => "Validate your {$schemaType} schema at Google's Rich Results Test (search.google.com/test/rich-results). Ensure these fields are present: {$missing}. Missing fields prevent rich snippets from appearing in search results.",
                ];
            }
        }

        return $issues;
    }

    /**
     * Validate hreflang tags on a page.
     *
     * @param  array<string, int|null>  $urlStatusMap
     * @return list<array{type: string, severity: string, message: string, recommendation: string}>
     */
    private function validateHreflang(CrawledPage $page, array $urlStatusMap): array
    {
        $issues = [];
        $validLangPattern = '/^[a-z]{2}(-[A-Za-z]{2,})?$|^x-default$/';
        $hasSelfRef = false;
        $pageUrlNorm = rtrim($page->url, '/');

        foreach ($page->hreflang ?? [] as $entry) {
            $lang = $entry['lang'] ?? '';
            $url = $entry['url'] ?? '';

            // Check language code validity
            if ($lang && ! preg_match($validLangPattern, $lang)) {
                $issues[] = [
                    'type' => 'hreflang_invalid_lang',
                    'severity' => 'medium',
                    'message' => "Invalid hreflang language code: \"{$lang}\". Expected ISO 639-1 format (e.g., en, en-US, x-default).",
                    'recommendation' => 'Use valid ISO 639-1 language codes: "en" for English, "ro" for Romanian, "en-US" for region-specific, or "x-default" for the fallback version.',
                ];

                break; // One per page
            }

            // Check if target URL is accessible
            $urlNorm = rtrim($url, '/');
            $targetStatus = $urlStatusMap[$urlNorm] ?? $urlStatusMap[$url] ?? null;
            if ($targetStatus !== null && ($targetStatus < 200 || $targetStatus >= 300)) {
                $issues[] = [
                    'type' => 'hreflang_broken_target',
                    'severity' => 'high',
                    'message' => "Hreflang target URL returns status {$targetStatus}: {$url}",
                    'recommendation' => 'Update the hreflang tag to point to a working URL (status 200). Broken hreflang targets confuse search engines about language versions.',
                ];
            }

            // Check self-reference
            if ($urlNorm === $pageUrlNorm) {
                $hasSelfRef = true;
            }
        }

        if (! $hasSelfRef && count($page->hreflang ?? []) > 0) {
            $issues[] = [
                'type' => 'hreflang_missing_self',
                'severity' => 'medium',
                'message' => 'Page has hreflang tags but no self-referencing hreflang entry.',
                'recommendation' => 'Add a hreflang tag pointing to this page\'s own URL. Every page with hreflang should include itself in the set of language alternatives.',
            ];
        }

        return $issues;
    }

    /**
     * @return array<string, mixed>
     */
    private function emptySummary(): array
    {
        return [
            'total_pages' => 0,
            'html_pages' => 0,
            'status_2xx' => 0,
            'status_3xx' => 0,
            'status_4xx' => 0,
            'status_5xx' => 0,
            'avg_response_time' => 0.0,
            'avg_word_count' => 0.0,
            'duplicate_titles' => 0,
            'duplicate_descriptions' => 0,
            'missing_titles' => 0,
            'missing_descriptions' => 0,
            'missing_h1' => 0,
            'multiple_h1' => 0,
            'thin_content' => 0,
            'broken_links' => 0,
            'orphan_pages' => 0,
            'noindex_pages' => 0,
            'pages_with_issues' => 0,
            'total_issues' => 0,
        ];
    }
}
