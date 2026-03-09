<?php

namespace App\Services;

class GoogleSearchConsoleService extends GoogleApiService
{
    private string $baseUrl = 'https://www.googleapis.com/webmasters/v3';

    public function listProperties(): array
    {
        $response = $this->api()->get("{$this->baseUrl}/sites");

        if ($response->failed()) {
            throw new \Exception('Failed to list Search Console properties: ' . $response->body());
        }

        $properties = [];
        foreach ($response->json('siteEntry', []) as $site) {
            $properties[] = [
                'site_url' => $site['siteUrl'],
                'permission_level' => $site['permissionLevel'] ?? 'unknown',
            ];
        }

        return $properties;
    }

    public function getOverview(string $siteUrl, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->baseUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => [],
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $rows = $response->json('rows', []);
        $row = $rows[0] ?? [];

        return [
            'clicks' => (int) ($row['clicks'] ?? 0),
            'impressions' => (int) ($row['impressions'] ?? 0),
            'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
            'position' => round($row['position'] ?? 0, 1),
        ];
    }

    public function getPerformanceOverTime(string $siteUrl, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->baseUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['date'],
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'date' => $row['keys'][0] ?? '',
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1),
            ];
        }

        return $data;
    }

    public function getTopQueries(string $siteUrl, string $startDate, string $endDate, int $limit = 20): array
    {
        $response = $this->api()->post("{$this->baseUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['query'],
            'rowLimit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'query' => $row['keys'][0] ?? '',
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1),
            ];
        }

        return $data;
    }

    public function getTopPages(string $siteUrl, string $startDate, string $endDate, int $limit = 20): array
    {
        $response = $this->api()->post("{$this->baseUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['page'],
            'rowLimit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'page' => $row['keys'][0] ?? '',
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1),
            ];
        }

        return $data;
    }

    public function getCountries(string $siteUrl, string $startDate, string $endDate, int $limit = 10): array
    {
        $response = $this->api()->post("{$this->baseUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['country'],
            'rowLimit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'country' => strtoupper($row['keys'][0] ?? ''),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1),
            ];
        }

        return $data;
    }

    public function getDevices(string $siteUrl, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->baseUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['device'],
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $device = $row['keys'][0] ?? '';
            $data[] = [
                'device' => match ($device) {
                    'MOBILE' => 'Mobile',
                    'DESKTOP' => 'Desktop',
                    'TABLET' => 'Tablet',
                    default => $device,
                },
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1),
            ];
        }

        return $data;
    }

    public function getSearchAppearance(string $siteUrl, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->baseUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['searchAppearance'],
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $displayNames = [
            'RICH_RESULT' => 'Rich Result',
            'FAQ_RICH_RESULT' => 'FAQ',
            'HOWTO_RICH_RESULT' => 'How-To',
            'AMP_BLUE_LINK' => 'AMP',
            'AMP_TOP_STORIES' => 'AMP Top Stories',
            'VIDEO' => 'Video',
            'WEB_LIGHT_RESULT' => 'Web Light',
            'REVIEW_SNIPPET' => 'Review Snippet',
            'EVENT_LISTING' => 'Event Listing',
            'JOB_LISTING' => 'Job Listing',
            'PRACTICE_PROBLEMS' => 'Practice Problems',
            'MATH_SOLVERS' => 'Math Solvers',
            'TRANSLATED_RESULT' => 'Translated Result',
            'MERCHANT_LISTINGS' => 'Merchant Listings',
            'BREADCRUMB' => 'Breadcrumb',
            'SITELINKS_SEARCHBOX' => 'Sitelinks Searchbox',
            'EDUCATION_Q_AND_A' => 'Education Q&A',
        ];

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $type = $row['keys'][0] ?? '';
            $data[] = [
                'type' => $displayNames[$type] ?? str_replace('_', ' ', ucwords(strtolower($type), '_')),
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1),
            ];
        }

        return $data;
    }

    public function getSitemaps(string $siteUrl): array
    {
        $response = $this->api()->get("{$this->baseUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/sitemaps");

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('sitemap', []) as $sitemap) {
            $contents = [];
            foreach ($sitemap['contents'] ?? [] as $content) {
                $contents[] = [
                    'type' => $content['type'] ?? 'unknown',
                    'submitted' => (int) ($content['submitted'] ?? 0),
                    'indexed' => (int) ($content['indexed'] ?? 0),
                ];
            }

            $data[] = [
                'path' => $sitemap['path'] ?? '',
                'type' => $sitemap['type'] ?? 'unknown',
                'last_submitted' => $sitemap['lastSubmitted'] ?? null,
                'last_downloaded' => $sitemap['lastDownloaded'] ?? null,
                'is_pending' => (bool) ($sitemap['isPending'] ?? false),
                'is_sitemaps_index' => (bool) ($sitemap['isSitemapsIndex'] ?? false),
                'warnings' => (int) ($sitemap['warnings'] ?? 0),
                'errors' => (int) ($sitemap['errors'] ?? 0),
                'contents' => $contents,
            ];
        }

        return $data;
    }

    public function inspectUrl(string $siteUrl, string $url): array
    {
        $response = $this->api()->post('https://searchconsole.googleapis.com/v1/urlInspection/index:inspect', [
            'inspectionUrl' => $url,
            'siteUrl' => $siteUrl,
        ]);

        if ($response->failed()) {
            throw new \Exception('URL Inspection API error: ' . $response->body());
        }

        $result = $response->json('inspectionResult', []);
        $indexStatus = $result['indexStatusResult'] ?? [];
        $mobileUsability = $result['mobileUsabilityResult'] ?? [];

        return [
            'index_status' => [
                'verdict' => $indexStatus['verdict'] ?? 'VERDICT_UNSPECIFIED',
                'coverage_state' => $indexStatus['coverageState'] ?? '',
                'crawled_as' => $indexStatus['crawledAs'] ?? '',
                'last_crawl_time' => $indexStatus['lastCrawlTime'] ?? null,
                'page_fetch_state' => $indexStatus['pageFetchState'] ?? '',
                'robots_txt_state' => $indexStatus['robotsTxtState'] ?? '',
                'indexing_state' => $indexStatus['indexingState'] ?? '',
                'referring_urls' => $indexStatus['referringUrls'] ?? [],
                'sitemap' => $indexStatus['sitemap'] ?? [],
            ],
            'mobile_usability' => [
                'verdict' => $mobileUsability['verdict'] ?? 'VERDICT_UNSPECIFIED',
                'issues' => array_map(fn($issue) => [
                    'issue_type' => $issue['issueType'] ?? '',
                    'severity' => $issue['severity'] ?? '',
                    'message' => $issue['message'] ?? '',
                ], $mobileUsability['issues'] ?? []),
            ],
        ];
    }

    /**
     * Get filtered results for drill-down: e.g. pages for a query, or queries for a page.
     */
    public function getFilteredResults(string $siteUrl, string $startDate, string $endDate, string $filterDim, string $filterValue, string $resultDim, int $limit = 20): array
    {
        $response = $this->api()->post("{$this->baseUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => [$resultDim],
            'dimensionFilterGroups' => [
                [
                    'filters' => [
                        [
                            'dimension' => $filterDim,
                            'operator' => 'equals',
                            'expression' => $filterValue,
                        ],
                    ],
                ],
            ],
            'rowLimit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'value' => $row['keys'][0] ?? '',
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1),
            ];
        }

        return $data;
    }

    /**
     * Get daily position history for a specific query.
     */
    public function getQueryPositionHistory(string $siteUrl, string $startDate, string $endDate, string $query): array
    {
        $response = $this->api()->post("{$this->baseUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
            'startDate' => $startDate,
            'endDate' => $endDate,
            'dimensions' => ['date'],
            'dimensionFilterGroups' => [
                [
                    'filters' => [
                        [
                            'dimension' => 'query',
                            'operator' => 'equals',
                            'expression' => $query,
                        ],
                    ],
                ],
            ],
        ]);

        if ($response->failed()) {
            throw new \Exception('Search Console API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'date' => $row['keys'][0] ?? '',
                'clicks' => (int) ($row['clicks'] ?? 0),
                'impressions' => (int) ($row['impressions'] ?? 0),
                'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                'position' => round($row['position'] ?? 0, 1),
            ];
        }

        return $data;
    }

    private function encodeSiteUrl(string $siteUrl): string
    {
        return urlencode($siteUrl);
    }
}
