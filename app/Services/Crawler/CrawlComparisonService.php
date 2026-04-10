<?php

declare(strict_types=1);

namespace App\Services\Crawler;

use App\Models\SiteCrawl;

class CrawlComparisonService
{
    /**
     * Compare two crawls of the same site.
     *
     * @return array{new_pages: array, disappeared_pages: array, new_issues: int, resolved_issues: int, status_changes: array, metrics: array}
     */
    public function compare(SiteCrawl $older, SiteCrawl $newer): array
    {
        $olderPages = $older->pages()->pluck('url')->all();
        $newerPages = $newer->pages()->pluck('url')->all();

        $olderSet = array_flip($olderPages);
        $newerSet = array_flip($newerPages);

        $newPages = array_values(array_diff($newerPages, $olderPages));
        $disappearedPages = array_values(array_diff($olderPages, $newerPages));

        // Compare issues
        $olderIssueCount = $older->pages()
            ->whereRaw("jsonb_array_length(COALESCE(issues, '[]'::jsonb)) > 0")
            ->count();
        $newerIssueCount = $newer->pages()
            ->whereRaw("jsonb_array_length(COALESCE(issues, '[]'::jsonb)) > 0")
            ->count();

        // Status code changes for common pages
        $statusChanges = [];
        $commonUrls = array_intersect($olderPages, $newerPages);

        if (count($commonUrls) > 0 && count($commonUrls) <= 1000) {
            $olderStatuses = $older->pages()
                ->whereIn('url', $commonUrls)
                ->pluck('status_code', 'url')
                ->all();

            $newerStatuses = $newer->pages()
                ->whereIn('url', $commonUrls)
                ->pluck('status_code', 'url')
                ->all();

            foreach ($commonUrls as $url) {
                $oldStatus = $olderStatuses[$url] ?? null;
                $newStatus = $newerStatuses[$url] ?? null;

                if ($oldStatus !== $newStatus && $oldStatus !== null && $newStatus !== null) {
                    $statusChanges[] = [
                        'url' => $url,
                        'old_status' => $oldStatus,
                        'new_status' => $newStatus,
                    ];
                }
            }
        }

        // Metrics comparison
        $olderSummary = $older->summary ?? [];
        $newerSummary = $newer->summary ?? [];

        return [
            'new_pages' => array_slice($newPages, 0, 100),
            'new_pages_count' => count($newPages),
            'disappeared_pages' => array_slice($disappearedPages, 0, 100),
            'disappeared_pages_count' => count($disappearedPages),
            'new_issues' => max(0, $newerIssueCount - $olderIssueCount),
            'resolved_issues' => max(0, $olderIssueCount - $newerIssueCount),
            'older_issues_count' => $olderIssueCount,
            'newer_issues_count' => $newerIssueCount,
            'status_changes' => array_slice($statusChanges, 0, 50),
            'metrics' => [
                'pages_crawled' => ['old' => $older->pages_crawled, 'new' => $newer->pages_crawled],
                'errors' => ['old' => $older->errors_count, 'new' => $newer->errors_count],
                'avg_response_time' => [
                    'old' => $olderSummary['avg_response_time'] ?? null,
                    'new' => $newerSummary['avg_response_time'] ?? null,
                ],
            ],
        ];
    }
}
