<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\SyncCloudflareZone;
use App\Models\SiteCloudflare;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1-57: SyncCloudflareZone swallowed exceptions on non-final attempts, so a
 * genuinely failing sync reported success on the first attempt (retries/backoff
 * were dead) and the data silently staled.
 *
 * P1-61: a failed settings fetch persisted getSslMode()'s 'off' default,
 * falsely reporting SSL as disabled. The failed sub-fetch must now carry the
 * last good value forward instead of writing a fabricated default.
 */
class SyncCloudflareZoneResilienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function zoneUrl(): string
    {
        return 'api.cloudflare.com/client/v4/zones/'.str_repeat('a', 32);
    }

    public function test_failed_zone_fetch_surfaces_and_persists_nothing(): void
    {
        $cf = SiteCloudflare::factory()->create([
            'status' => 'active',
            'ssl_mode' => 'full',
        ]);

        Http::fake([
            $this->zoneUrl() => Http::response([
                'success' => false,
                'errors' => [['code' => 9109, 'message' => 'Unauthorized.']],
            ], 403),
        ]);

        // P1-57: the failure is not swallowed — it surfaces so Laravel's retry
        // engages and failed() eventually fires.
        try {
            (new SyncCloudflareZone($cf))->handle();
            $this->fail('Expected the failing zone fetch to throw.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Cloudflare API request failed', $e->getMessage());
        }

        // Nothing fabricated was written; prior values are intact.
        $fresh = $cf->fresh();
        $this->assertSame('full', $fresh->ssl_mode);
        $this->assertNull($fresh->last_sync_at);
    }

    public function test_failed_ssl_fetch_keeps_prior_ssl_mode(): void
    {
        $cf = SiteCloudflare::factory()->create([
            'status' => 'active',
            'is_paused' => false,
            'ssl_mode' => 'full',
        ]);

        Http::fake([
            $this->zoneUrl().'/settings/ssl' => Http::response([
                'success' => false,
                'errors' => [['code' => 9109, 'message' => 'Unauthorized.']],
            ], 403),
            $this->zoneUrl() => Http::response([
                'success' => true,
                'result' => [
                    'status' => 'active',
                    'paused' => true,
                    'plan' => ['legacy_id' => 'pro'],
                ],
            ], 200),
        ]);

        (new SyncCloudflareZone($cf))->handle();

        $fresh = $cf->fresh();

        // P1-61: ssl_mode retains its last good value, NOT the 'off' default.
        $this->assertSame('full', $fresh->ssl_mode);
        // The zone-level fields still committed, and the sync is timestamped.
        $this->assertTrue($fresh->is_paused);
        $this->assertSame('pro', $fresh->plan_type);
        $this->assertNotNull($fresh->last_sync_at);
    }

    public function test_successful_sync_updates_ssl_and_timestamps(): void
    {
        $cf = SiteCloudflare::factory()->create([
            'ssl_mode' => 'off',
        ]);

        Http::fake([
            $this->zoneUrl().'/settings/ssl' => Http::response([
                'success' => true,
                'result' => ['value' => 'strict'],
            ], 200),
            $this->zoneUrl() => Http::response([
                'success' => true,
                'result' => [
                    'status' => 'active',
                    'paused' => false,
                    'plan' => ['legacy_id' => 'free'],
                ],
            ], 200),
        ]);

        (new SyncCloudflareZone($cf))->handle();

        $fresh = $cf->fresh();
        $this->assertSame('strict', $fresh->ssl_mode);
        $this->assertNotNull($fresh->last_sync_at);
    }
}
