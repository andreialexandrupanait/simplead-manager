<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\CloudflareConnection;
use App\Services\CloudflareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1-12 / E-59: CloudflareService::request() never checked the HTTP status, so a
 * rejected mutation (cache purge, DNS write, settings change) was treated as a
 * success. request() now surfaces both a non-2xx status and Cloudflare's
 * `success:false` envelope as an exception, so callers cannot record false data.
 */
class CloudflareServiceResilienceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): CloudflareService
    {
        Queue::fake();

        return new CloudflareService(CloudflareConnection::factory()->create());
    }

    public function test_mutation_throws_when_cloudflare_reports_success_false(): void
    {
        $service = $this->service();

        // HTTP 200 but the envelope reports failure — the classic false-success.
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => false,
                'errors' => [['code' => 1012, 'message' => 'Request must contain one of "prefixes".']],
                'result' => null,
            ], 200),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cloudflare API request failed');

        $service->purgeEverything(str_repeat('a', 32));
    }

    public function test_mutation_throws_on_http_error_status(): void
    {
        $service = $this->service();

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => false,
                'errors' => [['code' => 9109, 'message' => 'Unauthorized to access requested resource.']],
            ], 403),
        ]);

        $this->expectException(\RuntimeException::class);

        $service->purgeEverything(str_repeat('a', 32));
    }

    public function test_purge_returns_true_on_confirmed_success(): void
    {
        $service = $this->service();

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'errors' => [],
                'result' => ['id' => str_repeat('a', 32)],
            ], 200),
        ]);

        $this->assertTrue($service->purgeEverything(str_repeat('a', 32)));
    }

    public function test_settings_fetch_throws_on_error_instead_of_defaulting(): void
    {
        $service = $this->service();

        // A permissions error must not silently resolve to the 'off' default.
        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => false,
                'errors' => [['code' => 9109, 'message' => 'Unauthorized.']],
            ], 403),
        ]);

        $this->expectException(\RuntimeException::class);

        $service->getSslMode(str_repeat('a', 32));
    }

    // P2-51: zone_id validation before any request.

    public function test_invalid_zone_id_is_rejected_before_any_http_call(): void
    {
        $service = $this->service();
        Http::fake();

        try {
            $service->getZoneDetails('not-a-valid-zone-id');
            $this->fail('Expected an invalid zone id to throw.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid Cloudflare zone ID', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_invalid_zone_id_is_rejected_in_graphql_analytics(): void
    {
        $service = $this->service();
        Http::fake();

        // Wrong length / uppercase — a path/GraphQL-manipulation attempt.
        $this->expectException(\InvalidArgumentException::class);

        try {
            $service->getAnalytics('ABC123', '-1440');
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_valid_zone_id_passes_validation(): void
    {
        $service = $this->service();

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => true,
                'result' => ['id' => str_repeat('a', 32), 'name' => 'example.com'],
            ], 200),
        ]);

        $zone = $service->getZoneDetails(str_repeat('a', 32));

        $this->assertSame('example.com', $zone['name']);
    }

    // P2-53: only a genuine auth failure flips is_valid.

    public function test_transient_timeout_does_not_flip_is_valid(): void
    {
        $connection = CloudflareConnection::factory()->create(['is_valid' => true]);
        $service = new CloudflareService($connection);

        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $result = $service->validateToken();

        $this->assertTrue($result);
        $this->assertTrue($connection->fresh()->is_valid, 'A transient timeout must not invalidate the token.');
    }

    public function test_transient_server_error_does_not_flip_is_valid(): void
    {
        $connection = CloudflareConnection::factory()->create(['is_valid' => true]);
        $service = new CloudflareService($connection);

        Http::fake([
            'api.cloudflare.com/*' => Http::response('upstream error', 503),
        ]);

        $result = $service->validateToken();

        $this->assertTrue($result);
        $this->assertTrue($connection->fresh()->is_valid);
    }

    // P3-31: listDnsRecords must paginate — a zone with >100 records used to lose
    // everything past the first page.

    public function test_list_dns_records_paginates_beyond_100_records(): void
    {
        $service = $this->service();
        $zoneId = str_repeat('a', 32);

        Http::fake([
            'api.cloudflare.com/*dns_records*' => function ($request) {
                parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
                $page = (int) ($query['page'] ?? 1);

                // Two pages of 100 + 25 records. total_pages tells the loop to continue.
                $count = $page === 1 ? 100 : 25;
                $result = [];
                for ($i = 0; $i < $count; $i++) {
                    $result[] = ['id' => "rec-{$page}-{$i}", 'type' => 'A', 'name' => "r{$page}{$i}.example.com"];
                }

                return Http::response([
                    'success' => true,
                    'result' => $result,
                    'result_info' => ['page' => $page, 'per_page' => 100, 'total_pages' => 2, 'count' => $count],
                ], 200);
            },
        ]);

        $records = $service->listDnsRecords($zoneId);

        $this->assertCount(125, $records, 'All records across both pages must be returned.');
    }

    public function test_genuine_auth_failure_flips_is_valid_false(): void
    {
        $connection = CloudflareConnection::factory()->create(['is_valid' => true]);
        $service = new CloudflareService($connection);

        Http::fake([
            'api.cloudflare.com/*' => Http::response([
                'success' => false,
                'errors' => [['code' => 1000, 'message' => 'Invalid API Token.']],
            ], 401),
        ]);

        $result = $service->validateToken();

        $this->assertFalse($result);
        $this->assertFalse($connection->fresh()->is_valid, 'A real 401 must invalidate the token.');
    }
}
