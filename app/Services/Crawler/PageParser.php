<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use DOMDocument;
use DOMXPath;

class PageParser
{
    private const MAX_INTERNAL_LINKS = 200;

    private const MAX_EXTERNAL_LINKS = 100;

    private const MIN_WORDS_FOR_READABILITY = 50;

    /**
     * Parse HTML and extract SEO data for a single page.
     *
     * @return array<string, mixed>
     */
    public function parse(string $url, string $html, string $siteHost): array
    {
        $dom = new DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html, LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $title = $this->extractTitle($xpath);
        $metaDescription = $this->extractMetaContent($xpath, 'description')
            ?? $this->extractMetaProperty($xpath, 'og:description'); // fallback to OG
        $canonicalUrl = $this->extractCanonical($xpath);
        $metaRobots = $this->extractMetaContent($xpath, 'robots');

        $h1Tags = $this->extractTagTexts($xpath, '//h1');
        $h2Count = $this->countTags($xpath, '//h2');
        $h3Count = $this->countTags($xpath, '//h3');
        $h4Count = $this->countTags($xpath, '//h4');
        $h5Count = $this->countTags($xpath, '//h5');
        $h6Count = $this->countTags($xpath, '//h6');

        $bodyText = $this->extractBodyText($dom, $xpath);
        $wordCount = $this->countWords($bodyText);
        $readabilityScore = $this->computeReadability($bodyText, $wordCount);

        [$internalLinks, $externalLinks] = $this->extractLinks($xpath, $url, $siteHost);

        $images = $this->extractImages($xpath, $url);
        $imagesCount = count($images);
        $imagesWithoutAlt = count(array_filter($images, fn (array $img) => ($img['alt'] ?? '') === ''));

        $structuredDataTypes = $this->extractStructuredDataTypes($xpath);
        $hreflang = $this->extractHreflang($xpath);

        $ogTitle = $this->extractMetaProperty($xpath, 'og:title');
        $ogDescription = $this->extractMetaProperty($xpath, 'og:description');
        $ogImage = $this->extractMetaProperty($xpath, 'og:image');

        $scripts = $this->extractScripts($xpath, $url);
        $stylesheets = $this->extractStylesheets($xpath, $url);

        // Security checks
        $parsedUrlForScheme = parse_url($url);
        $isHttps = ($parsedUrlForScheme['scheme'] ?? '') === 'https';
        $hasMixedContent = $isHttps && $this->detectMixedContent($xpath);

        $parsedUrl = parse_url($url);
        $normalisedPageUrl = rtrim(
            ($parsedUrl['scheme'] ?? 'https').'://'.($parsedUrl['host'] ?? '').($parsedUrl['path'] ?? '/'),
            '/'
        );
        $normalisedCanonical = $canonicalUrl !== null
            ? rtrim($canonicalUrl, '/')
            : null;

        return [
            'title' => $title,
            'title_length' => mb_strlen($title ?? ''),
            'meta_description' => $metaDescription,
            'meta_desc_length' => mb_strlen($metaDescription ?? ''),
            'canonical_url' => $canonicalUrl,
            'canonical_self_ref' => $normalisedCanonical !== null && $normalisedCanonical === $normalisedPageUrl,
            'meta_robots' => $metaRobots,
            'h1_tags' => $h1Tags,
            'h1_count' => count($h1Tags),
            'h2_count' => $h2Count,
            'h3_count' => $h3Count,
            'h4_count' => $h4Count,
            'h5_count' => $h5Count,
            'h6_count' => $h6Count,
            'word_count' => $wordCount,
            'readability_score' => $readabilityScore,
            'internal_links' => $internalLinks,
            'internal_links_count' => count($internalLinks),
            'external_links' => $externalLinks,
            'external_links_count' => count($externalLinks),
            'images' => array_slice($images, 0, 100),
            'images_count' => $imagesCount,
            'images_without_alt' => $imagesWithoutAlt,
            'structured_data_types' => $structuredDataTypes,
            'hreflang' => $hreflang,
            'og_title' => $ogTitle,
            'og_description' => $ogDescription,
            'og_image' => $ogImage,
            'scripts' => array_slice($scripts, 0, 50),
            'stylesheets' => array_slice($stylesheets, 0, 50),
            'is_https' => $isHttps,
            'has_mixed_content' => $hasMixedContent,
        ];
    }

    // -------------------------------------------------------------------------
    // Extraction helpers
    // -------------------------------------------------------------------------

    private function extractTitle(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query('//title');
        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        $text = trim($nodes->item(0)->textContent ?? '');

        return $text !== '' ? $text : null;
    }

    private function extractMetaContent(DOMXPath $xpath, string $name): ?string
    {
        $nodes = $xpath->query(
            sprintf('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="%s"]', $name)
        );

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        /** @var \DOMElement $node */
        $node = $nodes->item(0);
        $content = trim($node->getAttribute('content'));

        return $content !== '' ? $content : null;
    }

    private function extractMetaProperty(DOMXPath $xpath, string $property): ?string
    {
        $nodes = $xpath->query(
            sprintf('//meta[@property="%s"]', $property)
        );

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        /** @var \DOMElement $node */
        $node = $nodes->item(0);
        $content = trim($node->getAttribute('content'));

        return $content !== '' ? $content : null;
    }

    private function extractCanonical(DOMXPath $xpath): ?string
    {
        $nodes = $xpath->query(
            '//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="canonical"]'
        );

        if ($nodes === false || $nodes->length === 0) {
            return null;
        }

        /** @var \DOMElement $node */
        $node = $nodes->item(0);
        $href = trim($node->getAttribute('href'));

        return $href !== '' ? $href : null;
    }

    /**
     * @return string[]
     */
    private function extractTagTexts(DOMXPath $xpath, string $query): array
    {
        $result = [];
        $nodes = $xpath->query($query);

        if ($nodes === false) {
            return $result;
        }

        foreach ($nodes as $node) {
            $text = trim($node->textContent ?? '');
            if ($text !== '') {
                $result[] = $text;
            }
        }

        return $result;
    }

    private function countTags(DOMXPath $xpath, string $query): int
    {
        $nodes = $xpath->query($query);

        return $nodes !== false ? $nodes->length : 0;
    }

    private function countTagsWithoutAttr(DOMXPath $xpath, string $tagQuery, string $attr): int
    {
        $nodes = $xpath->query(sprintf('%s[not(@%s) or @%s=""]', $tagQuery, $attr, $attr));

        return $nodes !== false ? $nodes->length : 0;
    }

    /**
     * @return array<int, array{url: string, alt: string, width: string|null, height: string|null}>
     */
    private function extractImages(DOMXPath $xpath, string $pageUrl): array
    {
        $images = [];
        // Query all img tags (with src, data-src, or srcset)
        $nodes = $xpath->query('//img');

        if ($nodes === false) {
            return $images;
        }

        foreach ($nodes as $node) {
            /** @var \DOMElement $node */
            // Try src first, then data-src (lazy loading), then data-lazy-src
            $src = trim($node->getAttribute('src'));
            if ($src === '' || str_starts_with($src, 'data:')) {
                $src = trim($node->getAttribute('data-src'));
            }
            if ($src === '' || str_starts_with($src, 'data:')) {
                $src = trim($node->getAttribute('data-lazy-src'));
            }
            // Try first entry from srcset if still empty
            if ($src === '' && $node->getAttribute('srcset')) {
                $srcset = explode(',', $node->getAttribute('srcset'));
                $firstEntry = trim($srcset[0] ?? '');
                $src = explode(' ', $firstEntry)[0] ?? '';
            }
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }

            // Resolve relative URLs
            if (! str_starts_with($src, 'http://') && ! str_starts_with($src, 'https://')) {
                $parsed = parse_url($pageUrl);
                $scheme = $parsed['scheme'] ?? 'https';
                $host = $parsed['host'] ?? '';
                $src = str_starts_with($src, '//')
                    ? "{$scheme}:{$src}"
                    : (str_starts_with($src, '/')
                        ? "{$scheme}://{$host}{$src}"
                        : "{$scheme}://{$host}/{$src}");
            }

            $images[] = [
                'url' => mb_substr($src, 0, 2048),
                'alt' => trim($node->getAttribute('alt')),
                'width' => $node->getAttribute('width') ?: null,
                'height' => $node->getAttribute('height') ?: null,
            ];
        }

        return $images;
    }

    /**
     * @return array<int, array{url: string, type: string}>
     */
    private function extractScripts(DOMXPath $xpath, string $pageUrl): array
    {
        $scripts = [];
        $nodes = $xpath->query('//script[@src]');

        if ($nodes === false) {
            return $scripts;
        }

        foreach ($nodes as $node) {
            /** @var \DOMElement $node */
            $src = trim($node->getAttribute('src'));
            if ($src === '' || str_starts_with($src, 'data:')) {
                continue;
            }

            $absoluteUrl = $this->resolveResourceUrl($src, $pageUrl);
            if ($absoluteUrl) {
                $scripts[] = [
                    'url' => mb_substr($absoluteUrl, 0, 2048),
                    'type' => $node->getAttribute('type') ?: 'text/javascript',
                ];
            }
        }

        return $scripts;
    }

    /**
     * @return array<int, array{url: string, media: string}>
     */
    private function extractStylesheets(DOMXPath $xpath, string $pageUrl): array
    {
        $sheets = [];
        $nodes = $xpath->query('//link[@rel="stylesheet"][@href]');

        if ($nodes === false) {
            return $sheets;
        }

        foreach ($nodes as $node) {
            /** @var \DOMElement $node */
            $href = trim($node->getAttribute('href'));
            if ($href === '') {
                continue;
            }

            $absoluteUrl = $this->resolveResourceUrl($href, $pageUrl);
            if ($absoluteUrl) {
                $sheets[] = [
                    'url' => mb_substr($absoluteUrl, 0, 2048),
                    'media' => $node->getAttribute('media') ?: 'all',
                ];
            }
        }

        return $sheets;
    }

    private function detectMixedContent(DOMXPath $xpath): bool
    {
        // Check for http:// in src/href attributes on an https page
        $checks = [
            '//img[starts-with(@src, "http://")]',
            '//script[starts-with(@src, "http://")]',
            '//link[@rel="stylesheet"][starts-with(@href, "http://")]',
        ];

        foreach ($checks as $query) {
            $nodes = $xpath->query($query);
            if ($nodes !== false && $nodes->length > 0) {
                return true;
            }
        }

        return false;
    }

    private function resolveResourceUrl(string $src, string $pageUrl): ?string
    {
        if (str_starts_with($src, 'http://') || str_starts_with($src, 'https://')) {
            return $src;
        }

        $parsed = parse_url($pageUrl);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        if (str_starts_with($src, '//')) {
            return $scheme.':'.$src;
        }

        if (str_starts_with($src, '/')) {
            return $scheme.'://'.$host.$src;
        }

        $basePath = dirname($parsed['path'] ?? '/');

        return $scheme.'://'.$host.rtrim($basePath, '/').'/'.$src;
    }

    private function extractBodyText(DOMDocument $dom, DOMXPath $xpath): string
    {
        // Remove script and style nodes before extracting text
        foreach ($xpath->query('//script | //style | //noscript') ?: [] as $node) {
            $node->parentNode?->removeChild($node);
        }

        $body = $dom->getElementsByTagName('body')->item(0);
        if ($body === null) {
            return '';
        }

        return trim(preg_replace('/\s+/', ' ', $body->textContent ?? '') ?? '');
    }

    private function countWords(string $text): int
    {
        if ($text === '') {
            return 0;
        }

        return str_word_count(strip_tags($text));
    }

    /**
     * Readability score based on average sentence length and word complexity.
     * Uses a simplified formula that works across languages (not English-only Flesch-Kincaid).
     *
     * Score 0-100: higher = easier to read.
     * Based on: avg sentence length (shorter = easier) and avg word length (shorter = easier).
     */
    private function computeReadability(string $text, int $wordCount): ?float
    {
        if ($wordCount < self::MIN_WORDS_FOR_READABILITY) {
            return null;
        }

        // Count sentences (split on . ! ? and Romanian-specific endings)
        $sentences = preg_split('/[.!?]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $sentenceCount = max(1, count($sentences));

        $avgSentenceLength = $wordCount / $sentenceCount;

        // Average word length (works for any language)
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $totalChars = 0;
        foreach ($words as $word) {
            $totalChars += mb_strlen(preg_replace('/[^\p{L}]/u', '', $word) ?? '');
        }
        $avgWordLength = $wordCount > 0 ? $totalChars / $wordCount : 0;

        // Score: penalize long sentences and long words
        // Base 100, -1 per word over 15 avg sentence length, -5 per char over 5 avg word length
        $score = 100
            - max(0, ($avgSentenceLength - 15) * 1.5)
            - max(0, ($avgWordLength - 5) * 8);

        return round(max(0, min(100, $score)), 1);
    }

    /**
     * Simple vowel-group syllable counter (used for English fallback).
     */
    private function countSyllables(string $word): int
    {
        $word = strtolower(preg_replace('/[^a-zA-Z]/', '', $word) ?? '');

        if ($word === '') {
            return 0;
        }

        // Count vowel groups
        $count = preg_match_all('/[aeiouy]+/', $word);

        // Trailing silent 'e' adjustment
        if (str_ends_with($word, 'e') && $count > 1) {
            $count--;
        }

        return max(1, (int) $count);
    }

    /**
     * Extract internal and external links, normalised to absolute URLs.
     *
     * @return array{0: array<int, array{url: string, anchor: string, nofollow: bool}>, 1: array<int, array{url: string, anchor: string, nofollow: bool}>}
     */
    private function extractLinks(DOMXPath $xpath, string $pageUrl, string $siteHost): array
    {
        $internalLinks = [];
        $externalLinks = [];

        $nodes = $xpath->query('//a[@href]');

        if ($nodes === false) {
            return [[], []];
        }

        foreach ($nodes as $node) {
            /** @var \DOMElement $node */
            $href = trim($node->getAttribute('href'));

            if ($href === '' || str_starts_with($href, 'mailto:') || str_starts_with($href, 'tel:') || str_starts_with($href, 'javascript:')) {
                continue;
            }

            $absoluteUrl = $this->resolveUrl($href, $pageUrl);
            if ($absoluteUrl === null) {
                continue;
            }

            $anchor = trim($node->textContent ?? '');
            $rel = strtolower($node->getAttribute('rel'));
            $nofollow = str_contains($rel, 'nofollow');

            $linkEntry = [
                'url' => $absoluteUrl,
                'anchor' => mb_substr($anchor, 0, 255),
                'nofollow' => $nofollow,
            ];

            $parsedHref = parse_url($absoluteUrl);
            $linkHost = $parsedHref['host'] ?? '';

            if ($this->isSameHost($linkHost, $siteHost)) {
                if (count($internalLinks) < self::MAX_INTERNAL_LINKS) {
                    $internalLinks[] = $linkEntry;
                }
            } else {
                if (count($externalLinks) < self::MAX_EXTERNAL_LINKS) {
                    $externalLinks[] = $linkEntry;
                }
            }
        }

        return [$internalLinks, $externalLinks];
    }

    private function isSameHost(string $linkHost, string $siteHost): bool
    {
        // Strip www. prefix for comparison
        $normalise = static fn (string $h): string => preg_replace('/^www\./', '', strtolower($h)) ?? strtolower($h);

        return $normalise($linkHost) === $normalise($siteHost);
    }

    private function resolveUrl(string $href, string $base): ?string
    {
        // Remove fragment
        $href = preg_replace('/#.*$/', '', $href) ?? $href;

        if ($href === '' || $href === '/') {
            $parsed = parse_url($base);

            return ($parsed['scheme'] ?? 'https').'://'.($parsed['host'] ?? '');
        }

        if (str_starts_with($href, 'http://') || str_starts_with($href, 'https://')) {
            return rtrim($href, '/');
        }

        // Protocol-relative
        if (str_starts_with($href, '//')) {
            $scheme = parse_url($base, PHP_URL_SCHEME) ?? 'https';

            return rtrim($scheme.':'.$href, '/');
        }

        $parsed = parse_url($base);
        $scheme = $parsed['scheme'] ?? 'https';
        $host = $parsed['host'] ?? '';

        if (! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        if (str_starts_with($href, '/')) {
            return rtrim($scheme.'://'.$host.$href, '/');
        }

        // Relative path — resolve against base path
        $basePath = dirname($parsed['path'] ?? '/');
        $resolved = rtrim($basePath, '/').'/'.$href;

        // Collapse any ../ or ./
        $parts = [];
        foreach (explode('/', $resolved) as $segment) {
            if ($segment === '..') {
                array_pop($parts);
            } elseif ($segment !== '.') {
                $parts[] = $segment;
            }
        }

        return rtrim($scheme.'://'.$host.implode('/', $parts), '/');
    }

    /**
     * @return array<int, string>
     */
    private function extractStructuredDataTypes(DOMXPath $xpath): array
    {
        $types = [];
        $nodes = $xpath->query('//script[@type="application/ld+json"]');

        if ($nodes === false) {
            return $types;
        }

        foreach ($nodes as $node) {
            $json = trim($node->textContent ?? '');
            if ($json === '') {
                continue;
            }

            $data = json_decode($json, true);
            if (! is_array($data)) {
                continue;
            }

            // Handle both single objects and @graph arrays
            $items = isset($data['@graph']) && is_array($data['@graph'])
                ? $data['@graph']
                : [$data];

            foreach ($items as $item) {
                if (isset($item['@type'])) {
                    $type = $item['@type'];
                    if (is_array($type)) {
                        foreach ($type as $t) {
                            if (is_string($t) && ! in_array($t, $types, true)) {
                                $types[] = $t;
                            }
                        }
                    } elseif (is_string($type) && ! in_array($type, $types, true)) {
                        $types[] = $type;
                    }
                }
            }
        }

        return $types;
    }

    /**
     * @return array<int, array{lang: string, url: string}>
     */
    private function extractHreflang(DOMXPath $xpath): array
    {
        $result = [];
        $nodes = $xpath->query(
            '//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="alternate" and @hreflang]'
        );

        if ($nodes === false) {
            return $result;
        }

        foreach ($nodes as $node) {
            /** @var \DOMElement $node */
            $lang = trim($node->getAttribute('hreflang'));
            $href = trim($node->getAttribute('href'));

            if ($lang !== '' && $href !== '') {
                $result[] = ['lang' => $lang, 'url' => $href];
            }
        }

        return $result;
    }
}
