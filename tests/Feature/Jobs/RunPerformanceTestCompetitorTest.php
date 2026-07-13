<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RunPerformanceTest;
use App\Models\PerformanceMonitor;
use App\Models\PerformanceTest;
use App\Models\Site;
use App\Services\PageSpeedService;
use App\Services\Security\SsrfGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P2-15: competitor URLs were collected but never benchmarked. RunPerformanceTest
 * now runs a PageSpeed probe against each configured competitor URL and stores the
 * result as an is_competitor row that the comparison surface reads back.
 */
class RunPerformanceTestCompetitorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();

        // Hermetic SSRF guard — treat every host as a public address.
        $this->app->instance(SsrfGuard::class, new class extends SsrfGuard
        {
            protected function resolveIps(string $host): array
            {
                return ['203.0.113.10'];
            }
        });

        Http::fake([
            'www.googleapis.com/*' => Http::response([
                'lighthouseResult' => [
                    'lighthouseVersion' => '11.0.0',
                    'categories' => ['performance' => ['score' => 0.87]],
                    'audits' => [],
                ],
            ], 200),
        ]);
    }

    public function test_competitor_url_is_benchmarked_and_stored(): void
    {
        $site = Site::factory()->create();
        $monitor = PerformanceMonitor::create([
            'site_id' => $site->id,
            'is_active' => true,
            'frequency' => 'manual',
            'interval_minutes' => 360,
            'competitor_urls' => ['https://competitor.example'],
        ]);

        (new RunPerformanceTest($monitor, 'mobile'))->handle(app(PageSpeedService::class));

        // A competitor benchmark row was produced with the real score.
        $competitor = PerformanceTest::competitors()
            ->where('performance_monitor_id', $monitor->id)
            ->where('competitor_url', 'https://competitor.example')
            ->first();

        $this->assertNotNull($competitor, 'A competitor benchmark row must be produced.');
        $this->assertSame('completed', $competitor->status);
        $this->assertSame(87, $competitor->performance_score);

        // The competitor row must NOT leak into the site's own test set (global scope).
        $ownTests = PerformanceTest::where('performance_monitor_id', $monitor->id)->get();
        $this->assertTrue($ownTests->every(fn ($t) => $t->competitor_url === null));
    }

    public function test_non_public_competitor_url_is_skipped(): void
    {
        // Guard that rejects everything (simulates an internal address).
        $this->app->instance(SsrfGuard::class, new class extends SsrfGuard
        {
            protected function resolveIps(string $host): array
            {
                return ['127.0.0.1'];
            }
        });

        $site = Site::factory()->create();
        $monitor = PerformanceMonitor::create([
            'site_id' => $site->id,
            'is_active' => true,
            'frequency' => 'manual',
            'interval_minutes' => 360,
            'competitor_urls' => ['https://internal.local'],
        ]);

        (new RunPerformanceTest($monitor, 'mobile'))->handle(app(PageSpeedService::class));

        $this->assertSame(
            0,
            PerformanceTest::competitors()->where('performance_monitor_id', $monitor->id)->count(),
            'A non-public competitor URL must not be benchmarked.'
        );
    }
}
