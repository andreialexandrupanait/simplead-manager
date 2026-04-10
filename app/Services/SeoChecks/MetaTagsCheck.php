<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

class MetaTagsCheck
{
    public function check(array $connectorData, ?array $gscData = null): array
    {
        $issues = [];

        $pages = $this->collectPages($connectorData);

        foreach ($pages as $page) {
            $issues = array_merge($issues, $this->checkPage($page));
        }

        return $issues;
    }

    private function collectPages(array $connectorData): array
    {
        $pages = [];

        $homepage = $connectorData['homepage'] ?? null;
        if ($homepage) {
            $pages[] = array_merge($homepage, ['_label' => 'Homepage']);
        }

        foreach ($connectorData['pages'] ?? [] as $page) {
            $pages[] = $page;
        }

        return $pages;
    }

    private function checkPage(array $page): array
    {
        $issues = [];
        $url = $page['url'] ?? null;
        $label = $page['_label'] ?? $url ?? 'Page';

        $issues = array_merge($issues, $this->checkTitle($page, $url, $label));
        $issues = array_merge($issues, $this->checkMetaDescription($page, $url, $label));
        $issues = array_merge($issues, $this->checkCanonical($page, $url, $label));
        $issues = array_merge($issues, $this->checkOpenGraph($page, $url, $label));
        $issues = array_merge($issues, $this->checkH1($page, $url, $label));

        return $issues;
    }

    private function checkTitle(array $page, ?string $url, string $label): array
    {
        $title = $page['title'] ?? null;

        if (! $title) {
            return [[
                'category' => 'meta_tags',
                'severity' => 'critical',
                'title' => "{$label}: missing title tag",
                'description' => 'This page has no title tag, which is critical for search engine indexing.',
                'url' => $url,
                'recommendation' => 'Add a descriptive title tag between 30 and 60 characters.',
                'meta' => null,
            ]];
        }

        $length = mb_strlen($title);

        if ($length < 30) {
            return [[
                'category' => 'meta_tags',
                'severity' => 'medium',
                'title' => "{$label}: title tag too short ({$length} chars)",
                'description' => "The title \"{$title}\" is too short and may not rank well.",
                'url' => $url,
                'recommendation' => 'Expand the title to at least 30 characters to better describe the page content.',
                'meta' => ['title' => $title, 'length' => $length],
            ]];
        }

        if ($length > 60) {
            return [[
                'category' => 'meta_tags',
                'severity' => 'medium',
                'title' => "{$label}: title tag too long ({$length} chars)",
                'description' => "The title \"{$title}\" exceeds 60 characters and may be truncated in search results.",
                'url' => $url,
                'recommendation' => 'Shorten the title to 60 characters or fewer.',
                'meta' => ['title' => $title, 'length' => $length],
            ]];
        }

        return [];
    }

    private function checkMetaDescription(array $page, ?string $url, string $label): array
    {
        $description = $page['meta_description'] ?? null;

        if (! $description) {
            return [[
                'category' => 'meta_tags',
                'severity' => 'high',
                'title' => "{$label}: missing meta description",
                'description' => 'No meta description found. Search engines may auto-generate one, often poorly.',
                'url' => $url,
                'recommendation' => 'Add a compelling meta description between 120 and 160 characters.',
                'meta' => null,
            ]];
        }

        $length = mb_strlen($description);

        if ($length < 120) {
            return [[
                'category' => 'meta_tags',
                'severity' => 'medium',
                'title' => "{$label}: meta description too short ({$length} chars)",
                'description' => 'The meta description is shorter than recommended and may not entice clicks.',
                'url' => $url,
                'recommendation' => 'Expand the meta description to at least 120 characters.',
                'meta' => ['length' => $length],
            ]];
        }

        if ($length > 160) {
            return [[
                'category' => 'meta_tags',
                'severity' => 'medium',
                'title' => "{$label}: meta description too long ({$length} chars)",
                'description' => 'The meta description exceeds 160 characters and will be truncated in search results.',
                'url' => $url,
                'recommendation' => 'Shorten the meta description to 160 characters or fewer.',
                'meta' => ['length' => $length],
            ]];
        }

        return [];
    }

    private function checkCanonical(array $page, ?string $url, string $label): array
    {
        $canonical = $page['canonical'] ?? null;

        if (! $canonical) {
            return [[
                'category' => 'meta_tags',
                'severity' => 'medium',
                'title' => "{$label}: missing canonical URL",
                'description' => 'No canonical tag found. This can lead to duplicate content issues.',
                'url' => $url,
                'recommendation' => 'Add a canonical link tag pointing to the preferred URL for this page.',
                'meta' => null,
            ]];
        }

        if (! filter_var($canonical, FILTER_VALIDATE_URL)) {
            return [[
                'category' => 'meta_tags',
                'severity' => 'high',
                'title' => "{$label}: canonical URL is invalid",
                'description' => "The canonical tag contains an invalid URL: \"{$canonical}\".",
                'url' => $url,
                'recommendation' => 'Fix the canonical tag to use a valid absolute URL.',
                'meta' => ['canonical' => $canonical],
            ]];
        }

        return [];
    }

    private function checkOpenGraph(array $page, ?string $url, string $label): array
    {
        $issues = [];

        if (empty($page['og_image'])) {
            $issues[] = [
                'category' => 'meta_tags',
                'severity' => 'low',
                'title' => "{$label}: missing OG image",
                'description' => 'No Open Graph image tag found. Social shares will lack a preview image.',
                'url' => $url,
                'recommendation' => 'Add an og:image meta tag with a representative image (1200×630 px recommended).',
                'meta' => null,
            ];
        }

        if (empty($page['og_title'])) {
            $issues[] = [
                'category' => 'meta_tags',
                'severity' => 'low',
                'title' => "{$label}: missing OG title",
                'description' => 'No Open Graph title tag found. Social shares may display the raw page title or nothing.',
                'url' => $url,
                'recommendation' => 'Add an og:title meta tag for better social media sharing appearance.',
                'meta' => null,
            ];
        }

        return $issues;
    }

    private function checkH1(array $page, ?string $url, string $label): array
    {
        $h1Tags = $page['h1_tags'] ?? [];
        $count = count($h1Tags);

        if ($count === 0) {
            return [[
                'category' => 'meta_tags',
                'severity' => 'high',
                'title' => "{$label}: missing H1 tag",
                'description' => 'No H1 heading found on this page. H1 is a primary on-page SEO signal.',
                'url' => $url,
                'recommendation' => 'Add exactly one H1 tag that describes the main topic of the page.',
                'meta' => null,
            ]];
        }

        if ($count > 1) {
            return [[
                'category' => 'meta_tags',
                'severity' => 'medium',
                'title' => "{$label}: multiple H1 tags ({$count} found)",
                'description' => "Found {$count} H1 tags. Multiple H1 tags dilute the primary heading signal.",
                'url' => $url,
                'recommendation' => 'Keep exactly one H1 tag per page. Convert additional H1s to H2 or lower.',
                'meta' => ['h1_count' => $count, 'h1_tags' => array_slice($h1Tags, 0, 5)],
            ]];
        }

        return [];
    }
}
