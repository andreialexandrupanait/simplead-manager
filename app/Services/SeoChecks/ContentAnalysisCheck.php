<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

class ContentAnalysisCheck
{
    public function check(array $connectorData, ?array $gscData = null): array
    {
        $issues = [];

        $pages = $connectorData['pages'] ?? [];

        foreach ($pages as $page) {
            $issues = array_merge($issues, $this->checkPage($page));
        }

        return $issues;
    }

    private function checkPage(array $page): array
    {
        $issues = [];
        $url = $page['url'] ?? null;
        $label = $url ?? 'Page';

        $issues = array_merge($issues, $this->checkWordCount($page, $url, $label));
        $issues = array_merge($issues, $this->checkH2($page, $url, $label));
        $issues = array_merge($issues, $this->checkLinkRatio($page, $url, $label));

        return $issues;
    }

    private function checkWordCount(array $page, ?string $url, string $label): array
    {
        $wordCount = (int) ($page['word_count'] ?? 0);

        if ($wordCount > 0 && $wordCount < 300) {
            return [[
                'category' => 'content',
                'severity' => 'low',
                'title' => "Thin content on page ({$wordCount} words)",
                'description' => "The page has only {$wordCount} words, which may be considered thin content by search engines.",
                'url' => $url,
                'recommendation' => 'Expand the page content to at least 300 words. Focus on providing genuine value to users.',
                'meta' => ['word_count' => $wordCount],
            ]];
        }

        return [];
    }

    private function checkH2(array $page, ?string $url, string $label): array
    {
        $h2Tags = $page['h2_tags'] ?? $page['headings']['h2'] ?? [];
        $h2Count = is_array($h2Tags) ? count($h2Tags) : (int) $h2Tags;

        if ($h2Count === 0) {
            return [[
                'category' => 'content',
                'severity' => 'low',
                'title' => 'No H2 headings on page',
                'description' => 'The page has no H2 heading tags. H2 tags help organize content and signal topic structure to search engines.',
                'url' => $url,
                'recommendation' => 'Add H2 headings to break up page content into logical sections.',
                'meta' => null,
            ]];
        }

        return [];
    }

    private function checkLinkRatio(array $page, ?string $url, string $label): array
    {
        $rawInternal = $page['internal_links'] ?? [];
        $rawExternal = $page['external_links'] ?? [];
        $internalLinks = (int) ($page['internal_link_count'] ?? (is_array($rawInternal) ? count($rawInternal) : (int) $rawInternal));
        $externalLinks = (int) ($page['external_link_count'] ?? (is_array($rawExternal) ? count($rawExternal) : (int) $rawExternal));

        if ($internalLinks === 0 && $externalLinks === 0) {
            return [];
        }

        if ($externalLinks > 0 && $externalLinks > $internalLinks) {
            return [[
                'category' => 'content',
                'severity' => 'low',
                'title' => 'High external link ratio on page',
                'description' => "The page has more external links ({$externalLinks}) than internal links ({$internalLinks}), which may dilute page authority.",
                'url' => $url,
                'recommendation' => 'Balance outbound links by adding more internal links to related content on your site.',
                'meta' => ['internal_links' => $internalLinks, 'external_links' => $externalLinks],
            ]];
        }

        return [];
    }
}
