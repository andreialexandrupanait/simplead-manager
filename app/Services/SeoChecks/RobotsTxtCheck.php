<?php

declare(strict_types=1);

namespace App\Services\SeoChecks;

class RobotsTxtCheck
{
    public function check(array $connectorData, ?array $gscData = null): array
    {
        $robots = $connectorData['robots_txt'] ?? null;

        if (! $robots) {
            return [];
        }

        $issues = [];

        if (! ($robots['exists'] ?? true)) {
            $issues[] = [
                'category' => 'robots',
                'severity' => 'medium',
                'title' => 'robots.txt file not found',
                'description' => 'No robots.txt file was found at the expected location. Search engines will crawl the site without guidance.',
                'url' => null,
                'recommendation' => 'Create a robots.txt file at the root of your domain to control crawler access and declare your sitemap.',
                'meta' => null,
            ];

            return $issues;
        }

        if ($robots['blocks_all'] ?? false) {
            $issues[] = [
                'category' => 'robots',
                'severity' => 'critical',
                'title' => 'robots.txt blocks all crawlers',
                'description' => 'The robots.txt contains a rule that disallows all crawlers from accessing the entire site. The site will not be indexed.',
                'url' => null,
                'recommendation' => 'Remove the "Disallow: /" rule from robots.txt or restrict it to specific directories only.',
                'meta' => ['content_snippet' => mb_substr($robots['content'] ?? '', 0, 500)],
            ];
        }

        if (! ($robots['has_sitemap'] ?? false)) {
            $issues[] = [
                'category' => 'robots',
                'severity' => 'medium',
                'title' => 'No sitemap declared in robots.txt',
                'description' => 'The robots.txt file does not include a Sitemap directive, making it harder for search engines to discover your sitemap.',
                'url' => null,
                'recommendation' => 'Add "Sitemap: https://yourdomain.com/sitemap.xml" to your robots.txt file.',
                'meta' => null,
            ];
        }

        foreach ($robots['issues'] ?? [] as $connectorIssue) {
            $issues[] = [
                'category' => 'robots',
                'severity' => 'medium',
                'title' => 'robots.txt issue detected',
                'description' => is_string($connectorIssue) ? $connectorIssue : ($connectorIssue['message'] ?? 'Unknown robots.txt issue'),
                'url' => null,
                'recommendation' => 'Review your robots.txt configuration and fix the reported issue.',
                'meta' => is_array($connectorIssue) ? $connectorIssue : null,
            ];
        }

        return $issues;
    }
}
