<?php

namespace App\Services;

class GoogleSearchConsoleService extends GoogleApiService
{
    private string $baseUrl = 'https://www.googleapis.com/webmasters/v3';
    private string $searchAnalyticsUrl = 'https://searchconsole.googleapis.com/v1';

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
        $response = $this->api()->post("{$this->searchAnalyticsUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
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
        $response = $this->api()->post("{$this->searchAnalyticsUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
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
        $response = $this->api()->post("{$this->searchAnalyticsUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
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
        $response = $this->api()->post("{$this->searchAnalyticsUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
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
        $response = $this->api()->post("{$this->searchAnalyticsUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
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
        $response = $this->api()->post("{$this->searchAnalyticsUrl}/sites/{$this->encodeSiteUrl($siteUrl)}/searchAnalytics/query", [
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

    private function encodeSiteUrl(string $siteUrl): string
    {
        return urlencode($siteUrl);
    }
}
