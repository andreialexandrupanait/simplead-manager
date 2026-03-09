<?php

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
            $query = '?' . http_build_query($params);
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
        $response = $this->request('GET', "/zones/{$zoneId}/analytics/dashboard?since={$since}&continuous=true");

        return $response['result'] ?? [];
    }

    // Private helper

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
