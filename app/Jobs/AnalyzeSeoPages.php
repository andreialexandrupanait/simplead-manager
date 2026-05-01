<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SeoAuditStatus;
use App\Enums\SeoIssueCategory;
use App\Enums\SeoIssueSeverity;
use App\Models\SeoAudit;
use App\Models\SeoImage;
use App\Models\SeoIssue;
use App\Models\SeoLink;
use App\Models\Site;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AnalyzeSeoPages implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;
    public array $backoff = [60];

    private array $issues = [];

    public function __construct(public Site $site, public SeoAudit $audit)
    {
        $this->onQueue('performance');
    }

    public function handle(): void
    {
        $trackerId = 'seo-audit-' . $this->site->id;
        $this->audit->markAs(SeoAuditStatus::Analyzing);
        JobTracker::progress($trackerId, 65, 'Analyzing pages...');

        $pages = $this->audit->pages()->get();
        $okPages = $pages->where('status_code', 200);

        $this->checkTitles($okPages);
        $this->checkDescriptions($okPages);
        $this->checkHeadings($okPages);
        $this->checkContent($okPages);
        $this->checkImages($okPages);
        $this->checkBrokenImages();
        $this->checkLinks($pages);
        $this->checkIndexability($pages);
        $this->checkCanonicals($okPages);
        $this->checkMobile($okPages);
        $this->checkSocialMeta($okPages);
        $this->checkStructuredData($okPages);
        $this->checkUrlStructure($okPages);

        $this->checkImageOptimization($okPages);
        $this->checkSchemaMarkup($okPages);
        $this->checkSitemap($pages);
        $this->checkRobotsTxt();
        $this->checkInternalLinking($okPages);
        $this->checkDuplicateContent($okPages);
        $this->checkCanonicalChains($okPages);
        $this->checkHreflang($okPages);
        $this->checkSchemaValidation($okPages);
        $this->checkOrphanPages($okPages);

        JobTracker::progress($trackerId, 80, 'Checking security headers...');
        $this->checkSecurityHeaders();
        $this->checkSsl();

        JobTracker::progress($trackerId, 85, 'Saving issues...');
        $this->persistIssues();

        JobTracker::progress($trackerId, 90, 'Analysis complete.');
    }

    public function failed(?\Throwable $e): void
    {
        $this->audit->markAs(SeoAuditStatus::Failed, $e?->getMessage());
        CircuitBreakerService::recordFailure($this->site, $e?->getMessage() ?? 'Analysis failed');
        JobTracker::fail('seo-audit-' . $this->site->id, 'Analysis failed');
    }

    private function checkTitles($pages): void
    {
        $min = (int) config('seo.analysis.title_min_length');
        $max = (int) config('seo.analysis.title_max_length');
        $titles = [];

        foreach ($pages as $p) {
            if (!$p->title || $p->title === '') {
                $this->addIssue(SeoIssueCategory::OnPage, SeoIssueSeverity::Critical, 'Missing title tag', 'Page has no title tag.', $p->url, 'Add a unique, descriptive title (30-60 chars).');
            } else {
                $titles[$p->title][] = $p->url;
                if ($p->title_length < $min) {
                    $this->addIssue(SeoIssueCategory::OnPage, SeoIssueSeverity::Medium, 'Title too short', "Title is {$p->title_length} chars (min: {$min}).", $p->url, 'Expand the title to 30-60 characters.');
                } elseif ($p->title_length > $max) {
                    $this->addIssue(SeoIssueCategory::OnPage, SeoIssueSeverity::Low, 'Title too long', "Title is {$p->title_length} chars (max: {$max}).", $p->url, 'Shorten to under 60 characters.');
                }
            }
        }

        foreach ($titles as $title => $urls) {
            if (count($urls) > 1) {
                $this->addIssue(SeoIssueCategory::OnPage, SeoIssueSeverity::High, 'Duplicate title', count($urls) . ' pages share the same title: "' . mb_substr($title, 0, 50) . '..."', $urls[0], 'Make each page title unique.');
            }
        }
    }

    private function checkDescriptions($pages): void
    {
        $min = (int) config('seo.analysis.description_min_length');
        $max = (int) config('seo.analysis.description_max_length');
        $descs = [];

        foreach ($pages as $p) {
            if (!$p->meta_description || $p->meta_description === '') {
                $this->addIssue(SeoIssueCategory::OnPage, SeoIssueSeverity::High, 'Missing meta description', 'Page has no meta description.', $p->url, 'Add a compelling meta description (70-160 chars).');
            } else {
                $descs[$p->meta_description][] = $p->url;
                if ($p->meta_description_length < $min) {
                    $this->addIssue(SeoIssueCategory::OnPage, SeoIssueSeverity::Low, 'Meta description too short', "Description is {$p->meta_description_length} chars (min: {$min}).", $p->url);
                } elseif ($p->meta_description_length > $max) {
                    $this->addIssue(SeoIssueCategory::OnPage, SeoIssueSeverity::Low, 'Meta description too long', "Description is {$p->meta_description_length} chars (max: {$max}).", $p->url);
                }
            }
        }
        foreach ($descs as $d => $urls) {
            if (count($urls) > 1) {
                $this->addIssue(SeoIssueCategory::OnPage, SeoIssueSeverity::Medium, 'Duplicate meta description', count($urls) . ' pages share the same description.', $urls[0], 'Write unique descriptions.');
            }
        }
    }

    private function checkHeadings($pages): void
    {
        foreach ($pages as $p) {
            $h1 = $p->h1_tags ?? [];
            if (empty($h1)) {
                $this->addIssue(SeoIssueCategory::OnPage, SeoIssueSeverity::High, 'Missing H1', 'Page has no H1 heading.', $p->url, 'Add a single H1 tag.');
            } elseif (count($h1) > 1) {
                $this->addIssue(SeoIssueCategory::OnPage, SeoIssueSeverity::Medium, 'Multiple H1 tags', 'Page has ' . count($h1) . ' H1 tags.', $p->url, 'Use only one H1 per page.');
            }
        }
    }

    private function checkContent($pages): void
    {
        $min = (int) config('seo.analysis.min_word_count');
        foreach ($pages as $p) {
            if ($p->word_count !== null && $p->word_count < $min) {
                $this->addIssue(SeoIssueCategory::OnPage, SeoIssueSeverity::Low, 'Thin content', "Page has only {$p->word_count} words (min: {$min}).", $p->url, 'Add more valuable content.');
            }
        }
    }

    private function checkImages($pages): void
    {
        foreach ($pages as $p) {
            if ($p->images_without_alt > 0) {
                $sev = $p->images_without_alt > 5 ? SeoIssueSeverity::High : SeoIssueSeverity::Medium;
                $this->addIssue(SeoIssueCategory::Images, $sev, 'Images without alt text', "{$p->images_without_alt} image(s) missing alt attribute.", $p->url, 'Add descriptive alt text to all images.');
            }
        }
    }

    private function checkBrokenImages(): void
    {
        $brokenImages = SeoImage::where('seo_audit_id', $this->audit->id)->where('is_broken', true)->count();
        if ($brokenImages > 0) {
            $sev = $brokenImages > 10 ? SeoIssueSeverity::High : SeoIssueSeverity::Medium;
            $this->addIssue(SeoIssueCategory::Images, $sev, 'Broken images', "{$brokenImages} image(s) return HTTP errors.", null, 'Fix or replace broken image URLs.');
        }
    }

    private function checkLinks($allPages): void
    {
        foreach ($allPages as $p) {
            if ($p->status_code >= 400) {
                $sev = $p->status_code >= 500 ? SeoIssueSeverity::Critical : SeoIssueSeverity::High;
                $this->addIssue(SeoIssueCategory::Links, $sev, "HTTP {$p->status_code} error", "Page returns {$p->status_code}.", $p->url, 'Fix or redirect this page.');
            }
        }

        $orphans = $allPages->filter(fn ($p) => $p->status_code === 200 && $p->inbound_internal_links === 0 && $p->depth > 0);
        foreach ($orphans as $p) {
            $this->addIssue(SeoIssueCategory::Links, SeoIssueSeverity::Medium, 'Orphan page', 'Page has no internal links pointing to it.', $p->url, 'Add internal links from relevant pages.');
        }

        $brokenExternal = SeoLink::where('seo_audit_id', $this->audit->id)->where('is_broken', true)->where('type', 'external')->count();
        if ($brokenExternal > 0) {
            $sev = $brokenExternal > 10 ? SeoIssueSeverity::High : SeoIssueSeverity::Medium;
            $this->addIssue(SeoIssueCategory::Links, $sev, 'Broken external links', "{$brokenExternal} external link(s) return errors.", null, 'Fix or remove broken external links.');
        }

        $brokenInternal = SeoLink::where('seo_audit_id', $this->audit->id)->where('is_broken', true)->where('type', 'internal')->count();
        if ($brokenInternal > 0) {
            $this->addIssue(SeoIssueCategory::Links, SeoIssueSeverity::High, 'Broken internal links', "{$brokenInternal} internal link(s) point to error pages.", null, 'Fix or update broken internal links.');
        }
    }

    private function checkIndexability($allPages): void
    {
        foreach ($allPages as $p) {
            if ($p->status_code !== 200) {
                continue;
            }
            if ($p->in_sitemap && $p->is_indexable === false && $p->meta_robots) {
                $this->addIssue(SeoIssueCategory::Indexability, SeoIssueSeverity::Critical, 'Noindex page in sitemap', 'Page is noindex but in sitemap.', $p->url, 'Remove noindex or remove from sitemap.');
            } elseif (! $p->in_sitemap && $p->is_indexable === false && $p->meta_robots && str_contains(strtolower($p->meta_robots), 'noindex')) {
                $this->addIssue(SeoIssueCategory::Indexability, SeoIssueSeverity::Medium, 'Page set to noindex', 'Page has meta robots noindex directive.', $p->url, 'Verify this page should be noindexed. If not, remove the noindex directive.');
            }
        }
    }

    private function checkCanonicals($pages): void
    {
        foreach ($pages as $p) {
            if ($p->canonical_url !== null && $p->is_self_canonical === false) {
                $this->addIssue(SeoIssueCategory::Indexability, SeoIssueSeverity::High, 'Canonical mismatch', 'Canonical points to: ' . mb_substr($p->canonical_url, 0, 80), $p->url, 'Verify canonical is correct.');
            }
            if ($p->canonical_url === null && $p->is_indexable) {
                $this->addIssue(SeoIssueCategory::Indexability, SeoIssueSeverity::Medium, 'Missing canonical', 'No canonical tag.', $p->url, 'Add a self-referencing canonical.');
            }
        }
    }

    private function checkMobile($pages): void
    {
        foreach ($pages->filter(fn ($p) => ! $p->has_viewport_meta) as $p) {
            $this->addIssue(SeoIssueCategory::Mobile, SeoIssueSeverity::High, 'Missing viewport meta', 'Page is missing the viewport meta tag.', $p->url, 'Add <meta name="viewport" content="width=device-width, initial-scale=1">.');
        }
    }

    private function checkSocialMeta($pages): void
    {
        $missing = $pages->filter(fn ($p) => empty($p->og_tags) || ! isset($p->og_tags['og:title']));
        $pct = $pages->count() > 0 ? (int) round(($missing->count() / $pages->count()) * 100) : 0;
        $sev = $pct > 50 ? SeoIssueSeverity::Medium : SeoIssueSeverity::Low;

        foreach ($missing as $p) {
            $this->addIssue(SeoIssueCategory::Social, $sev, 'Missing Open Graph tags', 'Page lacks og:title tag.', $p->url, 'Add OG meta tags for better social sharing.');
        }
    }

    private function checkStructuredData($pages): void
    {
        $withSchema = $pages->filter(fn($p) => !empty($p->structured_data_types))->count();
        if ($withSchema === 0 && $pages->count() > 0) {
            $this->addIssue(SeoIssueCategory::StructuredData, SeoIssueSeverity::Medium, 'No structured data', 'No pages have JSON-LD markup.', null, 'Add JSON-LD structured data (Organization, WebSite, Article).');
        }
    }

    private function checkUrlStructure($pages): void
    {
        $maxLen = (int) config('seo.analysis.url_max_length');

        foreach ($pages as $p) {
            $path = parse_url($p->url, PHP_URL_PATH) ?? '';
            if (strlen($p->url) > $maxLen) {
                $this->addIssue(SeoIssueCategory::Technical, SeoIssueSeverity::Low, 'Long URLs', 'URL exceeds ' . $maxLen . ' characters (' . strlen($p->url) . ' chars).', $p->url, 'Shorten the URL.');
            }
            if (str_contains($path, '_')) {
                $this->addIssue(SeoIssueCategory::Technical, SeoIssueSeverity::Info, 'URLs with underscores', 'URL path contains underscores.', $p->url, 'Use hyphens (-) instead of underscores in URLs.');
            }
            if ($path !== strtolower($path)) {
                $this->addIssue(SeoIssueCategory::Technical, SeoIssueSeverity::Low, 'Uppercase URLs', 'URL path contains uppercase characters.', $p->url, 'Use lowercase URLs to avoid duplicate content.');
            }
        }
    }

    private function checkSchemaMarkup($pages): void
    {
        $homepage = $pages->first(fn ($p) => $p->depth === 0);
        if ($homepage && empty($homepage->structured_data_types)) {
            $this->addIssue(SeoIssueCategory::StructuredData, SeoIssueSeverity::Medium, 'Homepage missing structured data', 'The homepage has no JSON-LD schema markup.', $homepage->url, 'Add Organization and WebSite schema to the homepage.');
        }

        if ($homepage && ! empty($homepage->structured_data_types)) {
            $types = $homepage->structured_data_types;
            if (! in_array('Organization', $types) && ! in_array('LocalBusiness', $types)) {
                $this->addIssue(SeoIssueCategory::StructuredData, SeoIssueSeverity::Low, 'Missing Organization schema', 'Homepage structured data does not include Organization or LocalBusiness.', $homepage->url, 'Add Organization schema to help search engines understand your business.');
            }
        }

        $innerPages = $pages->filter(fn ($p) => $p->depth > 0);
        $withBreadcrumb = $innerPages->filter(fn ($p) => ! empty($p->structured_data_types) && in_array('BreadcrumbList', $p->structured_data_types))->count();
        if ($innerPages->count() > 5 && $withBreadcrumb === 0) {
            $this->addIssue(SeoIssueCategory::StructuredData, SeoIssueSeverity::Low, 'No BreadcrumbList schema', 'No inner pages have BreadcrumbList structured data.', null, 'Add BreadcrumbList schema to inner pages for enhanced search snippets.');
        }
    }

    private function checkImageOptimization($pages): void
    {
        foreach ($pages->filter(fn ($p) => ($p->meta['images_without_lazy'] ?? 0) > 3) as $p) {
            $count = $p->meta['images_without_lazy'] ?? 0;
            $this->addIssue(SeoIssueCategory::Performance, SeoIssueSeverity::Medium, 'Images without lazy loading', "{$count} images on this page lack lazy loading.", $p->url, 'Add loading="lazy" to offscreen images to improve page load speed.');
        }
    }

    private function checkSitemap($allPages): void
    {
        $sitemapData = $this->audit->data['sitemap'] ?? null;
        if (! $sitemapData) {
            return;
        }

        if (! ($sitemapData['found'] ?? false)) {
            $this->addIssue(SeoIssueCategory::Technical, SeoIssueSeverity::Medium, 'No XML sitemap found', 'No sitemap.xml found at the expected location.', null, 'Create and submit an XML sitemap to help search engines discover your pages.');

            return;
        }

        foreach ($allPages->filter(fn ($p) => $p->in_sitemap && $p->status_code >= 400) as $p) {
            $this->addIssue(SeoIssueCategory::Technical, SeoIssueSeverity::High, 'Error pages in sitemap', "Page returns HTTP {$p->status_code} but is included in the sitemap.", $p->url, 'Remove this page from the sitemap or fix the HTTP error.');
        }

        foreach ($allPages->filter(fn ($p) => $p->is_indexable && ! $p->in_sitemap && $p->status_code === 200) as $p) {
            $this->addIssue(SeoIssueCategory::Technical, SeoIssueSeverity::Low, 'Indexable pages not in sitemap', 'Indexable page is not included in the sitemap.', $p->url, 'Add this page to the XML sitemap.');
        }
    }

    private function checkRobotsTxt(): void
    {
        $robots = $this->audit->robots_txt_data;
        if (! $robots || ! ($robots['exists'] ?? false)) {
            $this->addIssue(SeoIssueCategory::Technical, SeoIssueSeverity::Medium, 'No robots.txt found', 'No robots.txt file detected.', null, 'Create a robots.txt file with proper directives and a Sitemap reference.');

            return;
        }

        if (empty($robots['sitemap_urls'] ?? [])) {
            $this->addIssue(SeoIssueCategory::Technical, SeoIssueSeverity::Low, 'No Sitemap in robots.txt', 'robots.txt does not reference a Sitemap URL.', null, 'Add a Sitemap: directive to robots.txt.');
        }

        if (in_array('/', $robots['disallow_rules'] ?? [])) {
            $this->addIssue(SeoIssueCategory::Technical, SeoIssueSeverity::Critical, 'robots.txt blocks entire site', 'Disallow: / blocks all crawlers from the entire site.', null, 'Remove the Disallow: / rule unless intentional.');
        }
    }

    private function checkInternalLinking($pages): void
    {
        foreach ($pages->filter(fn ($p) => $p->depth > 3 && $p->status_code === 200) as $p) {
            $this->addIssue(SeoIssueCategory::Links, SeoIssueSeverity::Low, 'Deep pages', "Page is {$p->depth} clicks from homepage (recommended: max 3).", $p->url, 'Add internal links from higher-level pages to reduce click depth.');
        }

        foreach ($pages->filter(fn ($p) => $p->internal_link_count === 0 && $p->status_code === 200 && $p->depth > 0) as $p) {
            $this->addIssue(SeoIssueCategory::Links, SeoIssueSeverity::Medium, 'Dead-end pages', 'Page has no outbound internal links.', $p->url, 'Add internal links to guide users and distribute link equity.');
        }

        foreach ($pages->filter(fn ($p) => $p->internal_link_count > 100 && $p->status_code === 200) as $p) {
            $this->addIssue(SeoIssueCategory::Links, SeoIssueSeverity::Low, 'Link dilution', "Page has {$p->internal_link_count} outbound links (recommended: under 100).", $p->url, 'Reduce the number of links on this page to concentrate link equity.');
        }
    }

    private function checkDuplicateContent($pages): void
    {
        $hashMap = [];
        foreach ($pages as $p) {
            $hash = $p->meta['content_hash'] ?? null;
            if ($hash) {
                $hashMap[$hash][] = $p;
            }
        }

        foreach ($hashMap as $hash => $group) {
            if (count($group) < 2) {
                continue;
            }
            $urls = collect($group)->pluck('url')->toArray();
            $urlList = implode(', ', array_map(fn ($u) => mb_substr(parse_url($u, PHP_URL_PATH) ?: $u, 0, 60), array_slice($urls, 0, 5)));
            foreach ($group as $p) {
                $this->addIssue(
                    SeoIssueCategory::OnPage,
                    SeoIssueSeverity::High,
                    'Duplicate content',
                    'This page has identical body content as ' . (count($urls) - 1) . ' other page(s): ' . $urlList,
                    $p->url,
                    'Consolidate duplicate pages using canonical tags, 301 redirects, or by making content unique.'
                );
            }
        }
    }

    private function checkCanonicalChains($pages): void
    {
        $canonicalMap = [];
        foreach ($pages as $p) {
            if ($p->canonical_url && ! $p->is_self_canonical) {
                $canonicalMap[$p->url] = $p->canonical_url;
            }
        }

        foreach ($canonicalMap as $pageUrl => $canonicalUrl) {
            $target = $canonicalMap[$canonicalUrl] ?? null;
            if ($target) {
                $chain = $pageUrl . ' → ' . $canonicalUrl . ' → ' . $target;
                $this->addIssue(
                    SeoIssueCategory::Indexability,
                    SeoIssueSeverity::High,
                    'Canonical chain detected',
                    'Canonical tag points to a page that itself has a different canonical: ' . $chain,
                    $pageUrl,
                    'Set the canonical directly to the final target URL to avoid chains.'
                );
            }
        }
    }

    private function checkHreflang($pages): void
    {
        foreach ($pages as $p) {
            $hreflang = $p->meta['hreflang'] ?? [];
            if (empty($hreflang)) {
                continue;
            }

            $langs = collect($hreflang)->pluck('lang')->toArray();

            // Check for x-default
            if (! in_array('x-default', $langs, true)) {
                $this->addIssue(SeoIssueCategory::Technical, SeoIssueSeverity::Medium, 'Hreflang missing x-default', 'Page has hreflang tags but no x-default fallback.', $p->url, 'Add <link rel="alternate" hreflang="x-default" href="..."> for the default language version.');
            }

            // Check for self-reference
            $selfRef = collect($hreflang)->contains(fn ($h) => UrlNormalizerService::areEqual($h['href'], $p->url));
            if (! $selfRef) {
                $this->addIssue(SeoIssueCategory::Technical, SeoIssueSeverity::Medium, 'Hreflang missing self-reference', 'Page hreflang set does not include a self-referencing entry.', $p->url, 'Each page with hreflang should include itself in the hreflang set.');
            }

            // Check for empty hrefs
            foreach ($hreflang as $h) {
                if (empty($h['href'])) {
                    $this->addIssue(SeoIssueCategory::Technical, SeoIssueSeverity::High, 'Hreflang with empty href', "Hreflang tag for '{$h['lang']}' has an empty href attribute.", $p->url, 'Fix the hreflang href to point to the correct alternate URL.');
                }
            }
        }
    }

    private function checkSchemaValidation($pages): void
    {
        $requiredFields = [
            'Article' => ['headline', 'author', 'datePublished'],
            'Product' => ['name', 'description'],
            'LocalBusiness' => ['name', 'address'],
            'Organization' => ['name', 'url'],
            'WebSite' => ['name', 'url'],
            'BreadcrumbList' => ['itemListElement'],
            'FAQPage' => ['mainEntity'],
            'HowTo' => ['name', 'step'],
            'Recipe' => ['name', 'recipeIngredient'],
            'Event' => ['name', 'startDate', 'location'],
            'Person' => ['name'],
            'VideoObject' => ['name', 'description', 'thumbnailUrl', 'uploadDate'],
        ];

        foreach ($pages as $p) {
            $rawSchemas = $p->meta['structured_data_raw'] ?? [];

            foreach ($rawSchemas as $schema) {
                if (! empty($schema['_invalid'])) {
                    $this->addIssue(SeoIssueCategory::StructuredData, SeoIssueSeverity::High, 'Invalid JSON-LD', 'Structured data contains invalid JSON: ' . ($schema['_error'] ?? 'parse error'), $p->url, 'Fix the JSON syntax in the JSON-LD script tag.');
                    continue;
                }

                $items = [];
                if (isset($schema['@graph'])) {
                    $items = $schema['@graph'];
                } elseif (isset($schema['@type'])) {
                    $items = [$schema];
                }

                foreach ($items as $item) {
                    $type = $item['@type'] ?? null;
                    if (! $type || is_array($type)) {
                        continue;
                    }
                    $required = $requiredFields[$type] ?? null;
                    if (! $required) {
                        continue;
                    }

                    $missing = [];
                    foreach ($required as $field) {
                        if (empty($item[$field])) {
                            $missing[] = $field;
                        }
                    }
                    if (! empty($missing)) {
                        $fieldList = implode(', ', $missing);
                        $this->addIssue(
                            SeoIssueCategory::StructuredData,
                            SeoIssueSeverity::Medium,
                            "Incomplete {$type} schema",
                            "{$type} schema is missing required fields: {$fieldList}",
                            $p->url,
                            "Add the missing fields ({$fieldList}) to pass Google's Rich Results validation."
                        );
                    }
                }
            }
        }
    }

    private function checkOrphanPages($pages): void
    {
        $orphans = $pages->filter(fn ($p) => $p->inbound_internal_links === 0 && $p->depth > 0 && $p->status_code === 200);

        foreach ($orphans as $p) {
            $reasons = [];
            if (! $p->in_sitemap) {
                $reasons[] = 'not in sitemap';
            }
            if ($p->inbound_internal_links === 0) {
                $reasons[] = 'no internal links pointing to it';
            }

            $suggestion = 'Add internal links from related pages';
            if (! $p->in_sitemap) {
                $suggestion .= ' and include this page in the XML sitemap';
            }
            $suggestion .= '. Consider adding it to the site navigation or linking from high-traffic pages.';

            $this->addIssue(
                SeoIssueCategory::Links,
                SeoIssueSeverity::Medium,
                'Orphan page (deep analysis)',
                'This page is orphaned: ' . implode(', ', $reasons) . '. It may be hard for search engines and users to discover.',
                $p->url,
                $suggestion
            );
        }
    }

    private function checkSecurityHeaders(): void
    {
        try {
            $response = Http::timeout(10)->withUserAgent(config('seo.crawler.user_agent'))->withoutVerifying()->head($this->site->url);
            $headers = $response->headers();

            if (!isset($headers['Strict-Transport-Security'])) {
                $this->addIssue(SeoIssueCategory::Security, SeoIssueSeverity::Medium, 'Missing HSTS header', 'Strict-Transport-Security not set.', null, 'Add HSTS header.');
            }
            if (!isset($headers['X-Frame-Options'])) {
                $this->addIssue(SeoIssueCategory::Security, SeoIssueSeverity::Medium, 'Missing X-Frame-Options', 'X-Frame-Options not set.', null, 'Add X-Frame-Options: SAMEORIGIN.');
            }
            if (!isset($headers['X-Content-Type-Options'])) {
                $this->addIssue(SeoIssueCategory::Security, SeoIssueSeverity::Low, 'Missing X-Content-Type-Options', 'Header not set.', null, 'Add X-Content-Type-Options: nosniff.');
            }
            if (!isset($headers['Content-Security-Policy'])) {
                $this->addIssue(SeoIssueCategory::Security, SeoIssueSeverity::Low, 'Missing CSP', 'Content-Security-Policy not set.', null, 'Implement a Content-Security-Policy.');
            }

            $this->audit->update(['security_headers' => [
                'hsts' => isset($headers['Strict-Transport-Security']),
                'x_frame_options' => isset($headers['X-Frame-Options']),
                'x_content_type_options' => isset($headers['X-Content-Type-Options']),
                'csp' => isset($headers['Content-Security-Policy']),
            ]]);
        } catch (\Throwable $e) {
            Log::debug('SEO: security header check failed', ['error' => $e->getMessage()]);
        }
    }

    private function checkSsl(): void
    {
        $host = parse_url($this->site->url, PHP_URL_HOST);
        if (!$host) return;

        try {
            $ctx = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
            $socket = @stream_socket_client("ssl://{$host}:443", $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $ctx);

            if ($socket === false) {
                $this->addIssue(SeoIssueCategory::Security, SeoIssueSeverity::Critical, 'SSL connection failed', $errstr ?: 'Cannot connect to SSL port.', null, 'Fix SSL certificate.');
                $this->audit->update(['ssl_info' => ['valid' => false, 'error' => $errstr]]);
                return;
            }

            $params = stream_context_get_params($socket);
            fclose($socket);

            if (!isset($params['options']['ssl']['peer_certificate'])) return;
            $cert = openssl_x509_parse($params['options']['ssl']['peer_certificate']);
            if (!$cert) return;

            $validTo = $cert['validTo_time_t'] ?? 0;
            $daysLeft = (int) round(($validTo - time()) / 86400);
            $expiry = date('Y-m-d', $validTo);

            $this->audit->update(['ssl_info' => ['valid' => $daysLeft > 0, 'expiry' => $expiry, 'days_until_expiry' => $daysLeft, 'issuer' => $cert['issuer']['O'] ?? $cert['issuer']['CN'] ?? null]]);

            if ($daysLeft <= 0) {
                $this->addIssue(SeoIssueCategory::Security, SeoIssueSeverity::Critical, 'SSL expired', "Certificate expired on {$expiry}.", null, 'Renew SSL certificate immediately.');
            } elseif ($daysLeft < 30) {
                $this->addIssue(SeoIssueCategory::Security, $daysLeft < 7 ? SeoIssueSeverity::Critical : SeoIssueSeverity::High, 'SSL expiring soon', "Expires in {$daysLeft} days ({$expiry}).", null, 'Renew SSL certificate.');
            }
        } catch (\Throwable $e) {
            Log::debug('SEO: SSL check failed', ['error' => $e->getMessage()]);
        }
    }

    private function addIssue(SeoIssueCategory $cat, SeoIssueSeverity $sev, string $title, string $desc, ?string $url = null, ?string $rec = null): void
    {
        $this->issues[] = ['category' => $cat, 'severity' => $sev, 'title' => $title, 'description' => $desc, 'url' => $url, 'recommendation' => $rec];
    }

    private function persistIssues(): void
    {
        $records = [];

        foreach ($this->issues as $i) {
            $records[] = [
                'site_id' => $this->site->id, 'seo_audit_id' => $this->audit->id,
                'category' => $i['category']->value, 'severity' => $i['severity']->value,
                'title' => $i['title'], 'description' => $i['description'],
                'url' => $i['url'] ? mb_substr($i['url'], 0, 2048) : null,
                'recommendation' => $i['recommendation'],
                'created_at' => now(), 'updated_at' => now(),
            ];
        }

        // Count by unique issue groups (title+severity), not by individual records.
        // This prevents per-page issues from inflating severity counts and destroying scores.
        $counts = ['critical_count' => 0, 'high_count' => 0, 'medium_count' => 0, 'low_count' => 0, 'info_count' => 0];
        $grouped = collect($this->issues)->groupBy(fn ($i) => $i['title'] . '||' . $i['severity']->value);
        foreach ($grouped as $group) {
            $counts[$group->first()['severity']->value . '_count']++;
        }

        foreach (array_chunk($records, 100) as $chunk) {
            SeoIssue::insert($chunk);
        }

        $this->audit->update($counts);
    }
}
