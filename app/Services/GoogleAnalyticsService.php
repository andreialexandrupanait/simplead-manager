<?php

namespace App\Services;

class GoogleAnalyticsService extends GoogleApiService
{
    private string $baseUrl = 'https://analyticsdata.googleapis.com/v1beta';

    public function listProperties(): array
    {
        $properties = [];
        $pageToken = null;
        $maxPages = 20;

        for ($page = 0; $page < $maxPages; $page++) {
            $params = ['pageSize' => 200];
            if ($pageToken) {
                $params['pageToken'] = $pageToken;
            }

            $response = $this->api()->get('https://analyticsadmin.googleapis.com/v1beta/accountSummaries', $params);

            if ($response->failed()) {
                throw new \Exception('Failed to list Analytics properties: ' . $response->body());
            }

            foreach ($response->json('accountSummaries', []) as $account) {
                foreach ($account['propertySummaries'] ?? [] as $property) {
                    $properties[] = [
                        'property_id' => $property['property'],
                        'property_name' => $property['displayName'],
                        'account_name' => $account['displayName'] ?? '',
                    ];
                }
            }

            $pageToken = $response->json('nextPageToken');
            if (!$pageToken) {
                break;
            }
        }

        return $properties;
    }

    public function getOverview(string $propertyId, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'metrics' => [
                ['name' => 'totalUsers'],
                ['name' => 'newUsers'],
                ['name' => 'sessions'],
                ['name' => 'screenPageViews'],
                ['name' => 'bounceRate'],
                ['name' => 'averageSessionDuration'],
                ['name' => 'engagedSessions'],
                ['name' => 'engagementRate'],
            ],
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $row = $response->json('rows.0.metricValues', []);

        return [
            'total_users' => (int) ($row[0]['value'] ?? 0),
            'new_users' => (int) ($row[1]['value'] ?? 0),
            'sessions' => (int) ($row[2]['value'] ?? 0),
            'pageviews' => (int) ($row[3]['value'] ?? 0),
            'bounce_rate' => round((float) ($row[4]['value'] ?? 0) * 100, 2),
            'avg_session_duration' => round((float) ($row[5]['value'] ?? 0), 1),
            'engaged_sessions' => (int) ($row[6]['value'] ?? 0),
            'engagement_rate' => round((float) ($row[7]['value'] ?? 0) * 100, 2),
        ];
    }

    public function getUsersOverTime(string $propertyId, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'date'],
            ],
            'metrics' => [
                ['name' => 'totalUsers'],
                ['name' => 'newUsers'],
                ['name' => 'sessions'],
            ],
            'orderBys' => [
                ['dimension' => ['dimensionName' => 'date']],
            ],
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $date = $row['dimensionValues'][0]['value'] ?? '';
            $data[] = [
                'date' => substr($date, 0, 4) . '-' . substr($date, 4, 2) . '-' . substr($date, 6, 2),
                'users' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'new_users' => (int) ($row['metricValues'][1]['value'] ?? 0),
                'sessions' => (int) ($row['metricValues'][2]['value'] ?? 0),
            ];
        }

        return $data;
    }

    public function getTrafficSources(string $propertyId, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'sessionDefaultChannelGroup'],
            ],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'totalUsers'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'sessions'], 'desc' => true],
            ],
            'limit' => 10,
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $data = [];
        $total = 0;
        foreach ($response->json('rows', []) as $row) {
            $sessions = (int) ($row['metricValues'][0]['value'] ?? 0);
            $total += $sessions;
            $data[] = [
                'channel' => $row['dimensionValues'][0]['value'] ?? 'Unknown',
                'sessions' => $sessions,
                'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
            ];
        }

        foreach ($data as &$item) {
            $item['percentage'] = $total > 0 ? round(($item['sessions'] / $total) * 100, 1) : 0;
        }

        return $data;
    }

    public function getTopPages(string $propertyId, string $startDate, string $endDate, int $limit = 10): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'pagePath'],
                ['name' => 'pageTitle'],
            ],
            'metrics' => [
                ['name' => 'screenPageViews'],
                ['name' => 'totalUsers'],
                ['name' => 'averageSessionDuration'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'screenPageViews'], 'desc' => true],
            ],
            'limit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'path' => $row['dimensionValues'][0]['value'] ?? '/',
                'title' => $row['dimensionValues'][1]['value'] ?? '',
                'pageviews' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
                'avg_time' => round((float) ($row['metricValues'][2]['value'] ?? 0), 1),
            ];
        }

        return $data;
    }

    public function getDevices(string $propertyId, string $startDate, string $endDate): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'deviceCategory'],
            ],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'totalUsers'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'sessions'], 'desc' => true],
            ],
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $data = [];
        $total = 0;
        foreach ($response->json('rows', []) as $row) {
            $sessions = (int) ($row['metricValues'][0]['value'] ?? 0);
            $total += $sessions;
            $data[] = [
                'device' => ucfirst($row['dimensionValues'][0]['value'] ?? 'Unknown'),
                'sessions' => $sessions,
                'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
            ];
        }

        foreach ($data as &$item) {
            $item['percentage'] = $total > 0 ? round(($item['sessions'] / $total) * 100, 1) : 0;
        }

        return $data;
    }

    public function getCountries(string $propertyId, string $startDate, string $endDate, int $limit = 10): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'country'],
            ],
            'metrics' => [
                ['name' => 'totalUsers'],
                ['name' => 'sessions'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'totalUsers'], 'desc' => true],
            ],
            'limit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'country' => $row['dimensionValues'][0]['value'] ?? 'Unknown',
                'users' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'sessions' => (int) ($row['metricValues'][1]['value'] ?? 0),
            ];
        }

        return $data;
    }

    public function getReferralSources(string $propertyId, string $startDate, string $endDate, int $limit = 20): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'sessionSource'],
                ['name' => 'sessionMedium'],
            ],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'totalUsers'],
                ['name' => 'bounceRate'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'sessions'], 'desc' => true],
            ],
            'limit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $data = [];
        $total = 0;
        foreach ($response->json('rows', []) as $row) {
            $sessions = (int) ($row['metricValues'][0]['value'] ?? 0);
            $total += $sessions;
            $data[] = [
                'source' => $row['dimensionValues'][0]['value'] ?? '(direct)',
                'medium' => $row['dimensionValues'][1]['value'] ?? '(none)',
                'sessions' => $sessions,
                'users' => (int) ($row['metricValues'][1]['value'] ?? 0),
                'bounce_rate' => round((float) ($row['metricValues'][2]['value'] ?? 0) * 100, 1),
            ];
        }

        foreach ($data as &$item) {
            $item['percentage'] = $total > 0 ? round(($item['sessions'] / $total) * 100, 1) : 0;
        }

        return $data;
    }

    public function getLandingPages(string $propertyId, string $startDate, string $endDate, int $limit = 20): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'landingPage'],
            ],
            'metrics' => [
                ['name' => 'sessions'],
                ['name' => 'bounceRate'],
                ['name' => 'engagementRate'],
                ['name' => 'averageSessionDuration'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'sessions'], 'desc' => true],
            ],
            'limit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'page' => $row['dimensionValues'][0]['value'] ?? '/',
                'sessions' => (int) ($row['metricValues'][0]['value'] ?? 0),
                'bounce_rate' => round((float) ($row['metricValues'][1]['value'] ?? 0) * 100, 1),
                'engagement_rate' => round((float) ($row['metricValues'][2]['value'] ?? 0) * 100, 1),
                'avg_duration' => round((float) ($row['metricValues'][3]['value'] ?? 0), 1),
            ];
        }

        return $data;
    }

    public function getDemographics(string $propertyId, string $startDate, string $endDate): array
    {
        // Fetch age data
        $ageResponse = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'userAgeBracket'],
            ],
            'metrics' => [
                ['name' => 'totalUsers'],
            ],
            'orderBys' => [
                ['dimension' => ['dimensionName' => 'userAgeBracket']],
            ],
        ]);

        // Fetch gender data
        $genderResponse = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'userGender'],
            ],
            'metrics' => [
                ['name' => 'totalUsers'],
            ],
        ]);

        $age = [];
        if ($ageResponse->successful()) {
            foreach ($ageResponse->json('rows', []) as $row) {
                $age[] = [
                    'bracket' => $row['dimensionValues'][0]['value'] ?? 'unknown',
                    'users' => (int) ($row['metricValues'][0]['value'] ?? 0),
                ];
            }
        }

        $gender = [];
        if ($genderResponse->successful()) {
            foreach ($genderResponse->json('rows', []) as $row) {
                $gender[] = [
                    'gender' => ucfirst($row['dimensionValues'][0]['value'] ?? 'unknown'),
                    'users' => (int) ($row['metricValues'][0]['value'] ?? 0),
                ];
            }
        }

        return [
            'age' => $age,
            'gender' => $gender,
        ];
    }

    public function getRealtimeData(string $propertyId): array
    {
        // Total active users
        $totalResponse = $this->api()->post("{$this->baseUrl}/{$propertyId}:runRealtimeReport", [
            'metrics' => [
                ['name' => 'activeUsers'],
            ],
        ]);

        $totalActiveUsers = 0;
        if ($totalResponse->successful()) {
            $totalActiveUsers = (int) ($totalResponse->json('rows.0.metricValues.0.value') ?? 0);
        }

        // Top active pages
        $pagesResponse = $this->api()->post("{$this->baseUrl}/{$propertyId}:runRealtimeReport", [
            'dimensions' => [
                ['name' => 'unifiedScreenName'],
            ],
            'metrics' => [
                ['name' => 'activeUsers'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'activeUsers'], 'desc' => true],
            ],
            'limit' => 10,
        ]);

        $activePages = [];
        if ($pagesResponse->successful()) {
            foreach ($pagesResponse->json('rows', []) as $row) {
                $activePages[] = [
                    'page' => $row['dimensionValues'][0]['value'] ?? '',
                    'active_users' => (int) ($row['metricValues'][0]['value'] ?? 0),
                ];
            }
        }

        return [
            'active_users' => $totalActiveUsers,
            'active_pages' => $activePages,
            'fetched_at' => now()->toIso8601String(),
        ];
    }

    public function getCities(string $propertyId, string $startDate, string $endDate, int $limit = 10): array
    {
        $response = $this->api()->post("{$this->baseUrl}/{$propertyId}:runReport", [
            'dateRanges' => [
                ['startDate' => $startDate, 'endDate' => $endDate],
            ],
            'dimensions' => [
                ['name' => 'city'],
                ['name' => 'country'],
            ],
            'metrics' => [
                ['name' => 'totalUsers'],
            ],
            'orderBys' => [
                ['metric' => ['metricName' => 'totalUsers'], 'desc' => true],
            ],
            'limit' => $limit,
        ]);

        if ($response->failed()) {
            throw new \Exception('Analytics API error: ' . $response->body());
        }

        $data = [];
        foreach ($response->json('rows', []) as $row) {
            $data[] = [
                'city' => $row['dimensionValues'][0]['value'] ?? 'Unknown',
                'country' => $row['dimensionValues'][1]['value'] ?? '',
                'users' => (int) ($row['metricValues'][0]['value'] ?? 0),
            ];
        }

        return $data;
    }
}
