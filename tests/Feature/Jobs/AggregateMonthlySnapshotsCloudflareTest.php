<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\AggregateMonthlySnapshots;
use App\Models\CloudflareConnection;
use App\Models\Site;
use App\Models\SiteCloudflare;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\Sections\CloudflareGatherer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P2-02: the Cloudflare report section read cloudflare_* snapshot columns that
 * nothing ever populated, so it always rendered "not available". The monthly
 * aggregator now fills them from real zone analytics; a site without an active
 * Cloudflare zone leaves them null and the gatherer omits the section cleanly.
 */
class AggregateMonthlySnapshotsCloudflareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function fakeCloudflareAnalytics(): void
    {
        // Two daily buckets: 600+400 = 1000 requests, 500+300 = 800 cached,
        // 3_000_000 + 2_000_000 = 5_000_000 bytes. Cache hit ratio = 80.00%.
        Http::fake([
            'api.cloudflare.com/client/v4/graphql' => Http::response([
                'data' => [
                    'viewer' => [
                        'zones' => [[
                            'timeseries' => [
                                ['sum' => ['requests' => 600, 'cachedRequests' => 500, 'bytes' => 3_000_000, 'cachedBytes' => 2_000_000, 'threats' => 2, 'pageViews' => 100], 'uniq' => ['uniques' => 50], 'dimensions' => ['date' => '2026-06-01']],
                                ['sum' => ['requests' => 400, 'cachedRequests' => 300, 'bytes' => 2_000_000, 'cachedBytes' => 1_000_000, 'threats' => 1, 'pageViews' => 80], 'uniq' => ['uniques' => 40], 'dimensions' => ['date' => '2026-06-02']],
                            ],
                            'countries' => [],
                            'statuses' => [],
                        ]],
                    ],
                ],
            ], 200),
        ]);
    }

    /**
     * Invoke ONLY the Cloudflare aggregation step. handle() also runs pre-existing
     * sibling aggregations (analytics / search_console) that reference columns no
     * longer in the schema; in production each snapshot step autocommits and is
     * isolated by runStep(), but under the test's RefreshDatabase transaction a
     * failing sibling query would poison the whole transaction. Testing the CF step
     * in isolation keeps this focused on P2-02.
     */
    private function runCloudflareStep(int $year, int $month): void
    {
        $job = new AggregateMonthlySnapshots($year, $month);
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $method = new \ReflectionMethod($job, 'aggregateCloudflare');
        $method->setAccessible(true);
        $method->invoke($job, $start, $end);
    }

    public function test_cloudflare_columns_are_populated_from_zone_analytics(): void
    {
        $connection = CloudflareConnection::factory()->create(['is_valid' => true]);
        $site = Site::factory()->create();
        SiteCloudflare::factory()->create([
            'site_id' => $site->id,
            'cloudflare_connection_id' => $connection->id,
            'zone_id' => str_repeat('a', 32),
            'is_active' => true,
        ]);

        $this->fakeCloudflareAnalytics();

        $this->runCloudflareStep(2026, 6);

        $snapshot = SiteMonthlySnapshot::where('site_id', $site->id)
            ->where('year', 2026)->where('month', 6)->first();

        $this->assertNotNull($snapshot);
        $this->assertSame(1000, $snapshot->cloudflare_requests);
        $this->assertSame(5_000_000, $snapshot->cloudflare_bandwidth_bytes);
        $this->assertSame('80.00', (string) $snapshot->cloudflare_cache_hit_ratio);
    }

    public function test_site_without_active_cloudflare_leaves_columns_null_and_section_omitted(): void
    {
        $site = Site::factory()->create();

        // No Cloudflare API calls should be attempted for a site without a zone.
        Http::fake();

        $this->runCloudflareStep(2026, 6);

        $snapshot = SiteMonthlySnapshot::where('site_id', $site->id)
            ->where('year', 2026)->where('month', 6)->first();

        // Either no snapshot at all, or one with null Cloudflare columns — never zeros.
        if ($snapshot !== null) {
            $this->assertNull($snapshot->cloudflare_requests);
        }

        // The gatherer omits the whole section when the site has no Cloudflare zone.
        $result = (new CloudflareGatherer)->gather(
            $site,
            Carbon::create(2026, 6, 1),
            Carbon::create(2026, 6, 30),
            $snapshot,
            null,
            new ReportChartService,
            'en',
        );

        $this->assertSame([], $result);
    }

    public function test_gatherer_formats_populated_snapshot_columns(): void
    {
        $connection = CloudflareConnection::factory()->create(['is_valid' => true]);
        $site = Site::factory()->create();
        SiteCloudflare::factory()->create([
            'site_id' => $site->id,
            'cloudflare_connection_id' => $connection->id,
            'is_active' => true,
        ]);

        $snapshot = SiteMonthlySnapshot::create([
            'site_id' => $site->id,
            'year' => 2026,
            'month' => 6,
            'cloudflare_requests' => 1000,
            'cloudflare_bandwidth_bytes' => 5_000_000,
            'cloudflare_cache_hit_ratio' => 80.0,
        ]);

        $result = (new CloudflareGatherer)->gather(
            $site,
            Carbon::create(2026, 6, 1),
            Carbon::create(2026, 6, 30),
            $snapshot,
            null,
            new ReportChartService,
            'en',
        );

        $this->assertSame(1000, $result['total_requests']);
        $this->assertSame(5_000_000, $result['bandwidth']);
        $this->assertNotSame('N/A', $result['cache_hit_ratio_formatted']);
    }
}
