<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Exceptions\CloudflareRateLimitException;
use App\Jobs\SyncCloudflareZone;
use App\Models\SiteCloudflare;
use App\Services\CloudflareService;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Mockery;
use Tests\TestCase;

/**
 * P2-65: hitting the Cloudflare per-connection rate window inside a queued job
 * must DEFER (release back to the queue) — not throw / mark the job failed for
 * a condition that resolves itself once the window frees up.
 */
class SyncCloudflareZoneRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private function exhaustRateWindow(int $connectionId): void
    {
        $key = "cloudflare:{$connectionId}";
        for ($i = 0; $i < 200; $i++) {
            RateLimiter::hit($key, 60);
        }

        $this->assertTrue(RateLimiter::tooManyAttempts($key, 200));
    }

    public function test_rate_limited_sync_releases_instead_of_throwing(): void
    {
        // Prevent Site-creation side-effect jobs (e.g. favicon fetch) from
        // running inline on the sync test queue and making their own HTTP calls.
        Queue::fake();

        $cf = SiteCloudflare::factory()->create();

        // Guard against any real network call; the limiter should short-circuit
        // before HTTP anyway.
        Http::fake();
        $this->exhaustRateWindow($cf->cloudflare_connection_id);

        $released = null;
        $queueJob = Mockery::mock(QueueJobContract::class);
        $queueJob->shouldReceive('release')->once()->andReturnUsing(function ($delay) use (&$released) {
            $released = $delay;
        });

        $job = new SyncCloudflareZone($cf);
        $job->setJob($queueJob);

        // Must not throw: the transient limit is deferred, not surfaced.
        $job->handle();

        $this->assertNotNull($released, 'Job should have been released back to the queue.');
        $this->assertGreaterThanOrEqual(1, $released);

        // The limiter fired before any Cloudflare API call was made.
        Http::assertSentCount(0);

        // Nothing was persisted — the sync will simply retry after the window.
        $this->assertNull($cf->fresh()->last_sync_at);
    }

    public function test_service_throws_typed_exception_when_rate_limited(): void
    {
        Queue::fake();
        Http::fake();

        $cf = SiteCloudflare::factory()->create();
        $this->exhaustRateWindow($cf->cloudflare_connection_id);

        $service = new CloudflareService($cf->cloudflareConnection);

        $this->expectException(CloudflareRateLimitException::class);
        $service->getZoneDetails($cf->zone_id);
    }
}
