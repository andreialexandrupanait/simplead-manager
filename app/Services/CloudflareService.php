<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CloudflareRateLimitException;
use App\Models\CloudflareConnection;
use App\Models\Site;
use App\Models\SiteCloudflare;
use Illuminate\Http\Client\ConnectionException;
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
        $baseUrl = config('services.cloudflare.api_url', 'https://api.cloudflare.com/client/v4');

        try {
            $response = Http::withToken($this->connection->api_token)
                ->baseUrl($baseUrl)
                ->timeout(30)
                ->get('/user/tokens/verify');
        } catch (ConnectionException $e) {
            // P2-53: a transient network failure (timeout / DNS / connection
            // reset) must NOT flip is_valid — that silently disables sync for
            // ~24h until the next validation run. Preserve the stored validity
            // and rely on the next scheduled check / retry.
            return $this->connection->is_valid;
        }

        $status = $response->status();

        // P2-53: transient server-side / rate-limit failures also preserve
        // is_valid. Only a definitive answer from Cloudflare updates it.
        if ($status === 429 || $status >= 500) {
            return $this->connection->is_valid;
        }

        $json = $response->json() ?? [];

        // A genuine auth rejection (401/403) or an explicit success:false is a
        // real invalid-token signal and DOES flip is_valid.
        $valid = $response->successful() && ($json['success'] ?? false) === true;

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

    public function discoverDkimSelectors(string $zoneId, string $domain): array
    {
        $cacheKey = "cloudflare.dkim_selectors.{$zoneId}.".md5($domain);

        return \Illuminate\Support\Facades\Cache::remember($cacheKey, 300, function () use ($zoneId, $domain) {
            $records = $this->listDnsRecords($zoneId);
            $domain = mb_strtolower(trim($domain));
            $suffix = '._domainkey.'.$domain;
            $selectors = [];

            foreach ($records as $record) {
                $type = mb_strtoupper($record['type'] ?? '');
                if ($type !== 'TXT' && $type !== 'CNAME') {
                    continue;
                }

                $name = mb_strtolower($record['name'] ?? '');

                if (! str_ends_with($name, $suffix)) {
                    continue;
                }

                $selector = mb_substr($name, 0, -mb_strlen($suffix));

                if ($selector !== '' && preg_match('/^[a-z0-9._-]+$/', $selector)) {
                    $selectors[] = $selector;
                }
            }

            return array_values(array_unique($selectors));
        });
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

        return $this->getAnalyticsForRange($zoneId, $start, $end);
    }

    /**
     * Fetch zone analytics for an explicit [start, end] window. P2-02: the monthly
     * snapshot aggregator uses this to populate the Cloudflare report columns for a
     * specific calendar month instead of a now-anchored rolling window.
     */
    public function getAnalyticsForRange(string $zoneId, \Carbon\Carbon $start, \Carbon\Carbon $end): array
    {
        // P2-51: the zone id is interpolated straight into the GraphQL query,
        // so validate it before it can manipulate the query.
        $this->assertValidZoneId($zoneId);

        $start = $start->copy()->utc();
        $end = $end->copy()->utc();

        $minutes = (int) $start->diffInMinutes($end);

        $startIso = $start->toIso8601String();
        $endIso = $end->toIso8601String();

        // Use daily groups for 7d+, hourly otherwise
        // Both use datetime_geq/datetime_lt for filtering; the dimension field differs.
        if ($minutes >= 10080) {
            $timeseriesNode = 'httpRequests1dGroups';
            $timeDim = 'date';
            $timeFilter = "date_geq: \"{$start->toDateString()}\", date_leq: \"{$end->toDateString()}\"";
            $orderBy = 'date_ASC';
        } else {
            $timeseriesNode = 'httpRequests1hGroups';
            $timeDim = 'datetime';
            $timeFilter = "datetime_geq: \"{$startIso}\", datetime_lt: \"{$endIso}\"";
            $orderBy = 'datetime_ASC';
        }

        $query = <<<GRAPHQL
        {
          viewer {
            zones(filter: {zoneTag: "{$zoneId}"}) {
              timeseries: {$timeseriesNode}(
                filter: {{$timeFilter}}
                orderBy: [{$orderBy}]
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
        $this->assertWithinRateLimit();

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

    /**
     * P2-51: Cloudflare zone IDs are exactly 32 lowercase hex chars. Reject
     * anything else before it is interpolated into a REST path or GraphQL
     * query (path-manipulation / injection defence).
     */
    /**
     * P2-65: enforce the per-connection Cloudflare API rate window.
     *
     * When the window is exhausted this throws a typed
     * {@see CloudflareRateLimitException} carrying the seconds until it frees
     * up, so a queued caller (e.g. SyncCloudflareZone) can DEFER — release the
     * job back to the queue and retry after the window — instead of failing.
     * Non-job callers catch the same typed exception and degrade gracefully.
     */
    private function assertWithinRateLimit(): void
    {
        $rateLimitKey = "cloudflare:{$this->connection->id}";

        if (RateLimiter::tooManyAttempts($rateLimitKey, 200)) {
            // availableIn() can momentarily read 0 at the boundary; floor the
            // deferral at 1s so a released job never busy-loops.
            $retryAfter = max(1, RateLimiter::availableIn($rateLimitKey));

            throw new CloudflareRateLimitException($retryAfter);
        }

        RateLimiter::hit($rateLimitKey, 60);
    }

    private function assertValidZoneId(string $zoneId): void
    {
        if (preg_match('/^[a-f0-9]{32}$/', $zoneId) !== 1) {
            throw new \InvalidArgumentException('Invalid Cloudflare zone ID.');
        }
    }

    private function request(string $method, string $path, array $data = []): array
    {
        // P2-51: validate any zone id embedded in the request path at this
        // single choke point — every zone REST call routes through here.
        if (preg_match('#/zones/([^/?]+)#', $path, $matches) === 1) {
            $this->assertValidZoneId($matches[1]);
        }

        $this->assertWithinRateLimit();

        $baseUrl = config('services.cloudflare.api_url', 'https://api.cloudflare.com/client/v4');

        $request = Http::withToken($this->connection->api_token)
            ->baseUrl($baseUrl)
            ->timeout(30);

        $method = strtolower($method);

        /** @var Response $response */
        $response = empty($data)
            ? $request->$method($path)
            : $request->$method($path, $data);

        $json = $response->json() ?? [];

        // P1-12 / E-59: Cloudflare returns HTTP 200 with `success:false` on many
        // rejected mutations, and non-2xx on others. Never treat a rejected call
        // as a success — surface it so callers (cache purge, DNS write, settings
        // fetch) know the mutation did not apply and do not persist false data.
        if ($response->failed() || ($json['success'] ?? false) !== true) {
            $message = $json['errors'][0]['message'] ?? ($response->reason() ?: 'Unknown error');
            $code = (int) ($json['errors'][0]['code'] ?? $response->status());

            throw new \RuntimeException(
                "Cloudflare API request failed ({$method} {$path}): {$message}",
                $code,
            );
        }

        return $json;
    }
}
