<?php

namespace Tests\Unit\Services;

use App\Models\CloudflareConnection;
use App\Models\SiteCloudflare;
use App\Services\CloudflareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;
use Tests\Traits\CreatesSite;
use Tests\Traits\MocksWordPressApi;

class CloudflareServiceTest extends TestCase
{
    use RefreshDatabase, CreatesSite, MocksWordPressApi;

    private function createConnection(array $attributes = []): CloudflareConnection
    {
        return CloudflareConnection::factory()->create($attributes);
    }

    public function test_validate_token_returns_true_for_valid_token(): void
    {
        $this->fakeCloudflareApi();
        $connection = $this->createConnection();

        $service = new CloudflareService($connection);
        $result = $service->validateToken();

        $this->assertTrue($result);

        $connection->refresh();
        $this->assertTrue($connection->is_valid);
        $this->assertNotNull($connection->last_validated_at);
    }

    public function test_validate_token_returns_false_for_invalid_token(): void
    {
        $connection = $this->createConnection();

        Http::fake([
            'https://api.cloudflare.com/client/v4/user/tokens/verify' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Invalid API Token']],
            ], 403),
            'https://api.cloudflare.com/*' => Http::response([
                'success' => false,
                'result' => [],
            ]),
        ]);

        $service = new CloudflareService($connection);
        $result = $service->validateToken();

        $this->assertFalse($result);

        $connection->refresh();
        $this->assertFalse($connection->is_valid);
    }

    public function test_list_zones_returns_zones_array(): void
    {
        $connection = $this->createConnection();

        Http::fake([
            'https://api.cloudflare.com/client/v4/zones*' => Http::response([
                'success' => true,
                'result' => [
                    ['id' => 'zone-1', 'name' => 'example.com', 'status' => 'active'],
                    ['id' => 'zone-2', 'name' => 'test.com', 'status' => 'active'],
                ],
                'result_info' => ['total_count' => 2, 'total_pages' => 1, 'page' => 1],
            ]),
            'https://api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [],
            ]),
        ]);

        $service = new CloudflareService($connection);
        $zones = $service->listZones();

        $this->assertIsArray($zones);
        $this->assertCount(2, $zones);
        $this->assertEquals('example.com', $zones[0]['name']);
    }

    public function test_list_zones_paginates_through_all_pages(): void
    {
        $connection = $this->createConnection(['account_id' => null]);

        Http::fake([
            'https://api.cloudflare.com/client/v4/zones?per_page=50&page=1' => Http::response([
                'success' => true,
                'result' => [
                    ['id' => 'zone-1', 'name' => 'example.com', 'status' => 'active'],
                ],
                'result_info' => ['total_count' => 2, 'total_pages' => 2, 'page' => 1],
            ]),
            'https://api.cloudflare.com/client/v4/zones?per_page=50&page=2' => Http::response([
                'success' => true,
                'result' => [
                    ['id' => 'zone-2', 'name' => 'test.com', 'status' => 'active'],
                ],
                'result_info' => ['total_count' => 2, 'total_pages' => 2, 'page' => 2],
            ]),
            'https://api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [],
            ]),
        ]);

        $service = new CloudflareService($connection);
        $zones = $service->listZones();

        $this->assertIsArray($zones);
        $this->assertCount(2, $zones);
        $this->assertEquals('zone-1', $zones[0]['id']);
        $this->assertEquals('zone-2', $zones[1]['id']);
    }

    public function test_connect_site_to_zone_creates_site_cloudflare_record(): void
    {
        $connection = $this->createConnection();
        $site = $this->createSite();

        Http::fake([
            'https://api.cloudflare.com/client/v4/zones/zone-abc*' => Http::response([
                'success' => true,
                'result' => [
                    'id' => 'zone-abc',
                    'name' => 'example.com',
                    'status' => 'active',
                    'paused' => false,
                    'plan' => ['legacy_id' => 'free', 'name' => 'Free'],
                ],
            ]),
            'https://api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => [],
            ]),
        ]);

        $service = new CloudflareService($connection);
        $siteCloudflare = $service->connectSiteToZone($site, 'zone-abc');

        $this->assertInstanceOf(SiteCloudflare::class, $siteCloudflare);
        $this->assertEquals($site->id, $siteCloudflare->site_id);
        $this->assertEquals('zone-abc', $siteCloudflare->zone_id);
        $this->assertEquals('example.com', $siteCloudflare->zone_name);
        $this->assertEquals('free', $siteCloudflare->plan_type);
    }

    public function test_rate_limiting_after_200_requests(): void
    {
        $connection = $this->createConnection();

        Http::fake([
            'https://api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['status' => 'active'],
            ]),
        ]);

        $service = new CloudflareService($connection);

        // Simulate 200 rate limiter hits
        $rateLimitKey = "cloudflare:{$connection->id}";
        for ($i = 0; $i < 200; $i++) {
            RateLimiter::hit($rateLimitKey, 60);
        }

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('rate limit exceeded');

        $service->validateToken();
    }

    public function test_handles_api_errors_gracefully(): void
    {
        $connection = $this->createConnection();

        Http::fake([
            'https://api.cloudflare.com/client/v4/zones*' => Http::response([
                'success' => false,
                'errors' => [['message' => 'Authentication error']],
                'result' => null,
            ], 401),
            'https://api.cloudflare.com/*' => Http::response([
                'success' => false,
                'result' => [],
            ]),
        ]);

        $service = new CloudflareService($connection);
        $zones = $service->listZones();

        // Should return empty array when result is null
        $this->assertIsArray($zones);
        $this->assertEmpty($zones);
    }
}
