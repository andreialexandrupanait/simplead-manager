<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

class SitemapCheck
{
    public function check(array $connectorData, ?array $gscData = null): array
    {
        $sitemaps = $connectorData['sitemaps'] ?? null;

        if (! $sitemaps) {
            return [];
        }

        $issues = [];

        if (! ($sitemaps['found'] ?? false)) {
            $issues[] = [
                'category' => 'sitemap',
                'severity' => 'high',
                'title' => 'No XML sitemap found',
                'description' => 'No sitemap was detected on this site. Without a sitemap, search engines must rely entirely on link crawling to discover content.',
                'url' => null,
                'recommendation' => 'Generate and submit an XML sitemap. Most SEO plugins (Yoast, Rank Math) can create one automatically.',
                'meta' => null,
            ];

            return $issues;
        }

        $issues = array_merge($issues, $this->checkConnectorIssues($sitemaps));
        $issues = array_merge($issues, $this->checkGscData($gscData));
        $issues = array_merge($issues, $this->checkUrlCount($sitemaps));

        return $issues;
    }

    private function checkConnectorIssues(array $sitemaps): array
    {
        $issues = [];

        foreach ($sitemaps['issues'] ?? [] as $connectorIssue) {
            $issues[] = [
                'category' => 'sitemap',
                'severity' => 'high',
                'title' => 'Sitemap error detected',
                'description' => is_string($connectorIssue) ? $connectorIssue : ($connectorIssue['message'] ?? 'An error was found in the sitemap.'),
                'url' => null,
                'recommendation' => 'Fix the reported sitemap error and re-submit the sitemap to Google Search Console.',
                'meta' => is_array($connectorIssue) ? $connectorIssue : null,
            ];
        }

        return $issues;
    }

    private function checkGscData(?array $gscData): array
    {
        if (! $gscData) {
            return [];
        }

        $issues = [];
        $gscSitemaps = $gscData['sitemaps'] ?? [];

        foreach ($gscSitemaps as $gscSitemap) {
            $sitemapPath = $gscSitemap['path'] ?? 'sitemap';
            $errors = (int) ($gscSitemap['errors'] ?? 0);
            $warnings = (int) ($gscSitemap['warnings'] ?? 0);

            if ($errors > 0) {
                $issues[] = [
                    'category' => 'sitemap',
                    'severity' => 'high',
                    'title' => "Sitemap has {$errors} error(s) in Google Search Console",
                    'description' => "Google Search Console reports {$errors} error(s) for {$sitemapPath}.",
                    'url' => $sitemapPath,
                    'recommendation' => 'Review the sitemap errors in Google Search Console and fix the underlying issues.',
                    'meta' => ['sitemap_path' => $sitemapPath, 'errors' => $errors, 'warnings' => $warnings],
                ];
            }

            if ($warnings > 0) {
                $issues[] = [
                    'category' => 'sitemap',
                    'severity' => 'medium',
                    'title' => "Sitemap has {$warnings} warning(s) in Google Search Console",
                    'description' => "Google Search Console reports {$warnings} warning(s) for {$sitemapPath}.",
                    'url' => $sitemapPath,
                    'recommendation' => 'Review the sitemap warnings in Google Search Console.',
                    'meta' => ['sitemap_path' => $sitemapPath, 'errors' => $errors, 'warnings' => $warnings],
                ];
            }
        }

        return $issues;
    }

    private function checkUrlCount(array $sitemaps): array
    {
        $maps = $sitemaps['maps'] ?? [];
        $totalUrls = 0;

        foreach ($maps as $map) {
            $totalUrls += (int) ($map['url_count'] ?? $map['count'] ?? 0);
        }

        if ($totalUrls > 0 && $totalUrls < 5) {
            return [[
                'category' => 'sitemap',
                'severity' => 'low',
                'title' => "Sitemap contains very few URLs ({$totalUrls})",
                'description' => "The sitemap only lists {$totalUrls} URL(s), which may indicate missing content or a configuration issue.",
                'url' => null,
                'recommendation' => 'Verify the sitemap includes all important pages. Check SEO plugin settings to ensure all post types and taxonomies are included.',
                'meta' => ['url_count' => $totalUrls],
            ]];
        }

        return [];
    }
}
