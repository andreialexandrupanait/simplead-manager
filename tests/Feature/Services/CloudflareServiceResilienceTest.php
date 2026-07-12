<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\CloudflareConnection;
use App\Services\CloudflareService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
