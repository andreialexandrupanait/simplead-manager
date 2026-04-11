<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

class DuplicateContentCheck
{
    public function check(array $connectorData, ?array $gscData = null): array
    {
        $pages = $this->collectPages($connectorData);

        if (count($pages) < 2) {
            return [];
        }

        $issues = [];

        $issues = array_merge(
            $issues,
            $this->checkDuplicateTitles($pages),
            $this->checkDuplicateDescriptions($pages),
            $this->checkDuplicateH1s($pages),
            $this->checkThinContent($pages),
        );

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

    private function checkDuplicateTitles(array $pages): array
    {
        $titleGroups = [];

        foreach ($pages as $page) {
            $title = trim($page['title'] ?? '');
            if ($title === '') {
                continue;
            }

            $normalized = mb_strtolower($title);
            $titleGroups[$normalized][] = $page['url'] ?? $page['_label'] ?? 'Unknown';
        }

        $issues = [];

        foreach ($titleGroups as $title => $urls) {
            if (count($urls) < 2) {
                continue;
            }

            $urlList = implode(', ', array_slice($urls, 0, 5));
            $issues[] = [
                'category' => 'duplicate_content',
                'severity' => 'high',
                'title' => 'Duplicate title tag found on '.count($urls).' pages',
                'description' => "The title \"{$title}\" is used on multiple pages: {$urlList}. Search engines may struggle to determine which page to rank.",
                'url' => $urls[0],
                'recommendation' => 'Create unique, descriptive title tags for each page that accurately reflect its content.',
                'meta' => ['duplicate_title' => $title, 'pages' => array_slice($urls, 0, 10), 'count' => count($urls)],
            ];
        }

        return $issues;
    }

    private function checkDuplicateDescriptions(array $pages): array
    {
        $descGroups = [];

        foreach ($pages as $page) {
            $desc = trim($page['meta_description'] ?? '');
            if ($desc === '') {
                continue;
            }

            $normalized = mb_strtolower($desc);
            $descGroups[$normalized][] = $page['url'] ?? $page['_label'] ?? 'Unknown';
        }

        $issues = [];

        foreach ($descGroups as $desc => $urls) {
            if (count($urls) < 2) {
                continue;
            }

            $truncated = mb_strlen($desc) > 80 ? mb_substr($desc, 0, 80).'...' : $desc;
            $issues[] = [
                'category' => 'duplicate_content',
                'severity' => 'medium',
                'title' => 'Duplicate meta description on '.count($urls).' pages',
                'description' => "The meta description \"{$truncated}\" is shared across multiple pages.",
                'url' => $urls[0],
                'recommendation' => 'Write unique meta descriptions for each page to improve click-through rates.',
                'meta' => ['pages' => array_slice($urls, 0, 10), 'count' => count($urls)],
            ];
        }

        return $issues;
    }

    private function checkDuplicateH1s(array $pages): array
    {
        $h1Groups = [];

        foreach ($pages as $page) {
            $h1Tags = $page['h1_tags'] ?? [];
            if (empty($h1Tags)) {
                continue;
            }

            foreach ($h1Tags as $h1) {
                $normalized = mb_strtolower(trim($h1));
                if ($normalized === '') {
                    continue;
                }

                $h1Groups[$normalized][] = $page['url'] ?? $page['_label'] ?? 'Unknown';
            }
        }

        $issues = [];

        foreach ($h1Groups as $h1 => $urls) {
            $uniqueUrls = array_unique($urls);
            if (count($uniqueUrls) < 2) {
                continue;
            }

            $issues[] = [
                'category' => 'duplicate_content',
                'severity' => 'medium',
                'title' => 'Duplicate H1 heading on '.count($uniqueUrls).' pages',
                'description' => "The H1 \"{$h1}\" appears on multiple pages, which can confuse search engines about page uniqueness.",
                'url' => $uniqueUrls[0],
                'recommendation' => 'Ensure each page has a unique H1 tag relevant to its specific content.',
                'meta' => ['h1' => $h1, 'pages' => array_slice($uniqueUrls, 0, 10), 'count' => count($uniqueUrls)],
            ];
        }

        return $issues;
    }

    private function checkThinContent(array $pages): array
    {
        $issues = [];
        $thinPages = [];

        foreach ($pages as $page) {
            $wordCount = $page['word_count'] ?? 0;

            if ($wordCount > 0 && $wordCount < 100) {
                $thinPages[] = [
                    'url' => $page['url'] ?? $page['_label'] ?? 'Unknown',
                    'word_count' => $wordCount,
                ];
            }
        }

        if (count($thinPages) > 0) {
            $urlList = implode(', ', array_map(fn ($p) => "{$p['url']} ({$p['word_count']} words)", array_slice($thinPages, 0, 5)));
            $issues[] = [
                'category' => 'duplicate_content',
                'severity' => count($thinPages) >= 3 ? 'high' : 'medium',
                'title' => count($thinPages).' page(s) with very thin content (<100 words)',
                'description' => "Thin content pages detected: {$urlList}. These are at risk of being flagged as low-quality by search engines.",
                'url' => $thinPages[0]['url'],
                'recommendation' => 'Expand content to at least 300 words or consider consolidating thin pages with related content.',
                'meta' => ['thin_pages' => array_slice($thinPages, 0, 20), 'count' => count($thinPages)],
            ];
        }

        return $issues;
    }
}
