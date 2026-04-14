<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CloudflareConnection;
use App\Models\Site;
use App\Models\SiteCloudflare;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

class CloudflareService
{
    public function __construct(
        private CloudflareConnection $connection,
    ) {}

    public function validateToken(): bool
    {
        $response = $this->request('GET', '/user/tokens/verify');

        $valid = $response['success'] ?? false;

        $this->connection->update([
            'is_valid' => $valid,
            'last_validated_at' => now(),
        ]);

        return $valid;
    }

    public function listZones(): array
    {
        $params = ['per_page' => 50];

        $allZones = [];
        $page = 1;

        do {
            $params['page'] = $page;
            $query = '?'.http_build_query($params);
            $response = $this->request('GET', "/zones{$query}");

            $zones = $response['result'] ?? [];
            $allZones = array_merge($allZones, $zones);

            $totalPages = $response['result_info']['total_pages'] ?? 1;
            $page++;
        } while ($page <= $totalPages);

        return $allZones;
    }

    public function getZoneDetails(string $zoneId): array
    {
        $response = $this->request('GET', "/zones/{$zoneId}");

        return $response['result'] ?? [];
    }

    public function connectSiteToZone(Site $site, string $zoneId): SiteCloudflare
    {
        $zone = $this->getZoneDetails($zoneId);

        return SiteCloudflare::updateOrCreate(
            ['site_id' => $site->id],
            [
                'cloudflare_connection_id' => $this->connection->id,
                'zone_id' => $zoneId,
                'zone_name' => $zone['name'] ?? '',
                'plan_type' => $zone['plan']['legacy_id'] ?? $zone['plan']['name'] ?? null,
                'status' => $zone['status'] ?? 'active',
                'is_paused' => $zone['paused'] ?? false,
                'ssl_mode' => null,
                'connected_at' => now(),
            ]
        );
    }

    // DNS Methods

    public function listDnsRecords(string $zoneId): array
    {
        $response = $this->request('GET', "/zones/{$zoneId}/dns_records?per_page=100");

        return $response['result'] ?? [];
    }

    public function createDnsRecord(string $zoneId, array $data): array
    {
        $response = $this->request('POST', "/zones/{$zoneId}/dns_records", $data);

        return $response['result'] ?? [];
    }

    public function updateDnsRecord(string $zoneId, string $recordId, array $data): array
    {
        $response = $this->request('PUT', "/zones/{$zoneId}/dns_records/{$recordId}", $data);

        return $response['result'] ?? [];
    }

    public function deleteDnsRecord(string $zoneId, string $recordId): bool
    {
        $response = $this->request('DELETE', "/zones/{$zoneId}/dns_records/{$recordId}");

        return $response['success'] ?? false;
    }

    // Cache Methods

    public function purgeEverything(string $zoneId): bool
    {
        $response = $this->request('POST', "/zones/{$zoneId}/purge_cache", [
            'purge_everything' => true,
        ]);

        return $response['success'] ?? false;
    }

    public function purgeByUrls(string $zoneId, array $urls): bool
    {
        $response = $this->request('POST', "/zones/{$zoneId}/purge_cache", [
            'files' => $urls,
        ]);

        return $response['success'] ?? false;
    }

    public function purgeByTags(string $zoneId, array $tags): bool
    {
        $response = $this->request('POST', "/zones/{$zoneId}/purge_cache", [
            'tags' => $tags,
        ]);

        return $response['success'] ?? false;
    }

    public function purgeByPrefix(string $zoneId, array $prefixes): bool
    {
        $response = $this->request('POST', "/zones/{$zoneId}/purge_cache", [
            'prefixes' => $prefixes,
        ]);

        return $response['success'] ?? false;
    }

    // Security Methods

    public function getSecurityLevel(string $zoneId): string
    {
        $response = $this->request('GET', "/zones/{$zoneId}/settings/security_level");

        return $response['result']['value'] ?? 'medium';
    }

    public function setSecurityLevel(string $zoneId, string $level): bool
    {
        $response = $this->request('PATCH', "/zones/{$zoneId}/settings/security_level", [
            'value' => $level,
        ]);

        return $response['success'] ?? false;
    }

    public function listFirewallRules(string $zoneId): array
    {
        $response = $this->request('GET', "/zones/{$zoneId}/firewall/rules");

        return $response['result'] ?? [];
    }

    public function createFirewallRule(string $zoneId, array $data): array
    {
        $response = $this->request('POST', "/zones/{$zoneId}/firewall/rules", [$data]);

        return $response['result'] ?? [];
    }

    public function updateFirewallRule(string $zoneId, string $ruleId, array $data): array
    {
        $response = $this->request('PUT', "/zones/{$zoneId}/firewall/rules/{$ruleId}", $data);

        return $response['result'] ?? [];
    }

    public function deleteFirewallRule(string $zoneId, string $ruleId): bool
    {
        $response = $this->request('DELETE', "/zones/{$zoneId}/firewall/rules/{$ruleId}");

        return $response['success'] ?? false;
    }

    // WAF

    public function getWafStatus(string $zoneId): string
    {
        $response = $this->request('GET', "/zones/{$zoneId}/settings/waf");

        return $response['result']['value'] ?? 'off';
    }

    public function enableWaf(string $zoneId): array
    {
        return $this->request('PATCH', "/zones/{$zoneId}/settings/waf", ['value' => 'on']);
    }

    public function disableWaf(string $zoneId): array
    {
        return $this->request('PATCH', "/zones/{$zoneId}/settings/waf", ['value' => 'off']);
    }

    // Access Rules (IP blocking)

    public function listAccessRules(string $zoneId): array
    {
        $response = $this->request('GET', "/zones/{$zoneId}/firewall/access_rules/rules?per_page=50");

        return $response['result'] ?? [];
    }

    public function blockIpViaCloudflare(string $zoneId, string $ip, string $note = ''): array
    {
        $response = $this->request('POST', "/zones/{$zoneId}/firewall/access_rules/rules", [
            'mode' => 'block',
            'configuration' => [
                'target' => 'ip',
                'value' => $ip,
            ],
            'notes' => $note,
        ]);

        return $response['result'] ?? [];
    }

    public function deleteAccessRule(string $zoneId, string $ruleId): bool
    {
        $response = $this->request('DELETE', "/zones/{$zoneId}/firewall/access_rules/rules/{$ruleId}");

        return $response['success'] ?? false;
    }

    // SSL

    public function getSslMode(string $zoneId): string
    {
        $response = $this->request('GET', "/zones/{$zoneId}/settings/ssl");

        return $response['result']['value'] ?? 'off';
    }

    // Analytics

    public function getAnalytics(string $zoneId, string $since): array
    {
        $minutes = abs((int) $since);
        $end = now()->utc();
        $start = $end->copy()->subMinutes($minutes);

        $startIso = $start->toIso8601String();
        $endIso = $end->toIso8601String();

        // Use daily groups for 7d+, hourly otherwise
        if ($minutes >= 10080) {
            $timeseriesNode = 'httpRequests1dGroups';
            $timeDim = 'date';
            $timeFilter = "date_geq: \"{$start->toDateString()}\", date_leq: \"{$end->toDateString()}\"";
        } else {
            $timeseriesNode = 'httpRequests1hGroups';
            $timeDim = 'datetimeHour';
            $timeFilter = "datetimeHour_geq: \"{$startIso}\", datetimeHour_lt: \"{$endIso}\"";
        }

        $query = <<<GRAPHQL
        {
          viewer {
            zones(filter: {zoneTag: "{$zoneId}"}) {
              timeseries: {$timeseriesNode}(
                filter: {{$timeFilter}}
                orderBy: [{$timeDim}_ASC]
                limit: 1000
              ) {
                sum { requests cachedRequests bytes cachedBytes threats pageViews }
                uniq { uniques }
                dimensions { {$timeDim} }
              }
              countries: httpRequestsAdaptiveGroups(
                filter: {datetime_geq: "{$startIso}", datetime_lt: "{$endIso}"}
                orderBy: [count_DESC]
                limit: 15
              ) {
                count
                dimensions { clientCountryName }
              }
              statuses: httpRequestsAdaptiveGroups(
                filter: {datetime_geq: "{$startIso}", datetime_lt: "{$endIso}"}
                orderBy: [count_DESC]
                limit: 15
              ) {
                count
                dimensions { edgeResponseStatus }
              }
            }
          }
        }
        GRAPHQL;

        $response = $this->graphqlRequest($query);

        $zones = $response['data']['viewer']['zones'] ?? [];
        if (empty($zones)) {
            return [];
        }
        $zone = $zones[0];

        $ts = $zone['timeseries'] ?? [];
        $countryGroups = $zone['countries'] ?? [];
        $statusGroups = $zone['statuses'] ?? [];

        // Aggregate totals from timeseries
        $totalReqs = 0;
        $cachedReqs = 0;
        $totalBytes = 0;
        $cachedBytes = 0;
        $totalThreats = 0;
        $totalPageviews = 0;
        $totalUniques = 0;
        $timeseries = [];

        foreach ($ts as $point) {
            $sum = $point['sum'] ?? [];
            $uniq = $point['uniq'] ?? [];
            $dim = $point['dimensions'] ?? [];

            $reqs = $sum['requests'] ?? 0;
            $cached = $sum['cachedRequests'] ?? 0;

            $totalReqs += $reqs;
            $cachedReqs += $cached;
            $totalBytes += $sum['bytes'] ?? 0;
            $cachedBytes += $sum['cachedBytes'] ?? 0;
            $totalThreats += $sum['threats'] ?? 0;
            $totalPageviews += $sum['pageViews'] ?? 0;
            $totalUniques += $uniq['uniques'] ?? 0;

            $timeseries[] = [
                'since' => $dim[$timeDim] ?? '',
                'requests' => ['all' => $reqs, 'cached' => $cached],
            ];
        }

        // Country map
        $countryMap = [];
        foreach ($countryGroups as $c) {
            $name = $c['dimensions']['clientCountryName'] ?? 'Unknown';
            $countryMap[$name] = $c['count'] ?? 0;
        }

        // Status code map
        $statusMap = [];
        foreach ($statusGroups as $s) {
            $code = $s['dimensions']['edgeResponseStatus'] ?? 0;
            $statusMap[(int) $code] = $s['count'] ?? 0;
        }

        return [
            'totals' => [
                'requests' => [
                    'all' => $totalReqs,
                    'cached' => $cachedReqs,
                    'country' => $countryMap,
                    'http_status' => $statusMap,
                ],
                'bandwidth' => [
                    'all' => $totalBytes,
                    'cached' => $cachedBytes,
                ],
                'threats' => ['all' => $totalThreats],
                'pageviews' => ['all' => $totalPageviews],
                'uniques' => ['all' => $totalUniques],
            ],
            'timeseries' => $timeseries,
        ];
    }

    // Private helpers

    private function graphqlRequest(string $query): array
    {
        $rateLimitKey = "cloudflare:{$this->connection->id}";

        if (RateLimiter::tooManyAttempts($rateLimitKey, 200)) {
            throw new \RuntimeException('Cloudflare API rate limit exceeded.');
        }

        RateLimiter::hit($rateLimitKey, 60);

        $response = Http::withToken($this->connection->api_token)
            ->timeout(30)
            ->post('https://api.cloudflare.com/client/v4/graphql', [
                'query' => $query,
            ]);

        $json = $response->json() ?? [];

        if (! empty($json['errors'])) {
            throw new \RuntimeException('Cloudflare analytics error: '.($json['errors'][0]['message'] ?? 'Unknown error'));
        }

        return $json;
    }

    private function request(string $method, string $path, array $data = []): array
    {
        $rateLimitKey = "cloudflare:{$this->connection->id}";

        if (RateLimiter::tooManyAttempts($rateLimitKey, 200)) {
            throw new \RuntimeException('Cloudflare API rate limit exceeded. Please wait before making more requests.');
        }

        RateLimiter::hit($rateLimitKey, 60);

        $baseUrl = config('services.cloudflare.api_url', 'https://api.cloudflare.com/client/v4');

        $request = Http::withToken($this->connection->api_token)
            ->baseUrl($baseUrl)
            ->timeout(30);

        $method = strtolower($method);

        /** @var Response $response */
        $response = empty($data)
            ? $request->$method($path)
            : $request->$method($path, $data);

        return $response->json() ?? [];
    }
}
