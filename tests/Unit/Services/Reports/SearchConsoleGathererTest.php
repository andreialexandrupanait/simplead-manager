<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Reports;

use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\Sections\SearchConsoleGatherer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SearchConsoleGathererTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Carbon $periodStart;

    private Carbon $periodEnd;

    private ReportChartService $chartService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();
        SiteHealthState::create(['site_id' => $this->site->id, 'circuit_state' => 'closed']);

        $this->periodStart = now()->subMonth()->startOfMonth();
        $this->periodEnd = now()->subMonth()->endOfMonth();
        $this->chartService = new ReportChartService;
    }

    private function createSnapshot(?array $overrides = []): SiteMonthlySnapshot
    {
        return SiteMonthlySnapshot::create(array_merge([
            'site_id' => $this->site->id,
            'year' => (int) $this->periodStart->format('Y'),
            'month' => (int) $this->periodStart->format('n'),
            'uptime_percentage' => null,
            'uptime_avg_response_ms' => null,
            'uptime_incidents_count' => null,
            'uptime_down_checks' => null,
            'backups_total' => null,
            'backups_successful' => null,
            'backups_failed' => null,
            'updates_applied' => null,
            'security_avg_score' => null,
            'performance_avg_desktop' => null,
            'performance_avg_mobile' => null,
            'analytics_users' => null,
            'analytics_sessions' => null,
            'analytics_pageviews' => null,
            'search_console_clicks' => 3200,
            'search_console_impressions' => 85000,
            'search_console_avg_position' => 12.5,
            'cloudflare_requests' => null,
            'cloudflare_bandwidth_bytes' => null,
            'cloudflare_cache_hit_ratio' => null,
        ], $overrides));
    }

    /**
     * Create empty stub cache entries for the data types the gatherer always accesses.
     * Without these the gatherer crashes trying to read ->data on a null collection entry.
     */
    private function createStubCacheEntries(array $excludeTypes = []): void
    {
        $stubTypes = ['queries', 'pages', 'countries', 'devices', 'performance_over_time'];

        foreach ($stubTypes as $type) {
            if (in_array($type, $excludeTypes, true)) {
                continue;
            }
            SearchConsoleCache::create([
                'site_id' => $this->site->id,
                'date_range' => '28d',
                'start_date' => $this->periodStart->toDateString(),
                'end_date' => $this->periodEnd->toDateString(),
                'data_type' => $type,
                'data' => [],
                'fetched_at' => now(),
                'expires_at' => now()->addHours(24),
            ]);
        }
    }

    #[Test]
    public function supports_search_console_section(): void
    {
        $gatherer = new SearchConsoleGatherer;

        $this->assertTrue($gatherer->supports('search_console'));
        $this->assertFalse($gatherer->supports('analytics'));
    }

    #[Test]
    public function returns_empty_without_cache(): void
    {
        $gatherer = new SearchConsoleGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function returns_overview_data_from_cache(): void
    {
        // The gatherer maps: clicks->total_clicks, impressions->total_impressions,
        // ctr->avg_ctr, position->avg_position.
        SearchConsoleCache::create([
            'site_id' => $this->site->id,
            'date_range' => '28d',
            'start_date' => $this->periodStart->toDateString(),
            'end_date' => $this->periodEnd->toDateString(),
            'data_type' => 'overview',
            'data' => [
                'clicks' => 3200,
                'impressions' => 85000,
                'ctr' => 376,
                'position' => 12.5,
            ],
            'fetched_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
        $this->createStubCacheEntries();

        $gatherer = new SearchConsoleGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertArrayHasKey('overview', $result);
        $this->assertEquals(3200, $result['overview']['total_clicks']);
        $this->assertEquals(85000, $result['overview']['total_impressions']);
    }

    #[Test]
    public function includes_queries_data(): void
    {
        SearchConsoleCache::create([
            'site_id' => $this->site->id,
            'date_range' => '28d',
            'start_date' => $this->periodStart->toDateString(),
            'end_date' => $this->periodEnd->toDateString(),
            'data_type' => 'overview',
            'data' => ['clicks' => 100, 'impressions' => 1000, 'ctr' => 1000, 'position' => 5],
            'fetched_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
        SearchConsoleCache::create([
            'site_id' => $this->site->id,
            'date_range' => '28d',
            'start_date' => $this->periodStart->toDateString(),
            'end_date' => $this->periodEnd->toDateString(),
            'data_type' => 'queries',
            'data' => [
                ['query' => 'laravel tips', 'clicks' => 50, 'impressions' => 200, 'ctr' => 25, 'position' => 3.2],
                ['query' => 'php best practices', 'clicks' => 30, 'impressions' => 150, 'ctr' => 20, 'position' => 5.1],
            ],
            'fetched_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
        $this->createStubCacheEntries(['queries']);

        $gatherer = new SearchConsoleGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertNotEmpty($result['queries']);
        $this->assertCount(2, $result['queries']);
    }

    #[Test]
    public function handles_partial_cache_data(): void
    {
        // Only overview — stub out the remaining types so the gatherer does not crash.
        SearchConsoleCache::create([
            'site_id' => $this->site->id,
            'date_range' => '28d',
            'start_date' => $this->periodStart->toDateString(),
            'end_date' => $this->periodEnd->toDateString(),
            'data_type' => 'overview',
            'data' => ['clicks' => 50, 'impressions' => 500, 'ctr' => 1000, 'position' => 8],
            'fetched_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
        $this->createStubCacheEntries();

        $gatherer = new SearchConsoleGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertArrayHasKey('overview', $result);
        $this->assertArrayHasKey('queries', $result);
        $this->assertEmpty($result['queries']);
    }
}
