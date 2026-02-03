<?php

namespace App\Services;

class GoogleAnalyticsService extends GoogleApiService
{
    private string $baseUrl = 'https://analyticsdata.googleapis.com/v1beta';

    public function listProperties(): array
    {
        $response = $this->api()->get('https://analyticsadmin.googleapis.com/v1beta/accountSummaries');

        if ($response->failed()) {
            throw new \Exception('Failed to list Analytics properties: ' . $response->body());
        }

        $properties = [];
        foreach ($response->json('accountSummaries', []) as $account) {
            foreach ($account['propertySummaries'] ?? [] as $property) {
                $properties[] = [
                    'property_id' => $property['property'],
                    'property_name' => $property['displayName'],
                    'account_name' => $account['displayName'] ?? '',
                ];
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
