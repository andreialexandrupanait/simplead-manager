<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Reports;

use App\Models\AnalyticsCache;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\Sections\AnalyticsGatherer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class AnalyticsGathererTest extends TestCase
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
            'analytics_users' => 1200,
            'analytics_sessions' => 1500,
            'analytics_pageviews' => 5000,
            'search_console_clicks' => null,
            'search_console_impressions' => null,
            'search_console_avg_position' => null,
            'cloudflare_requests' => null,
            'cloudflare_bandwidth_bytes' => null,
            'cloudflare_cache_hit_ratio' => null,
        ], $overrides));
    }

    #[Test]
    public function supports_analytics_section(): void
    {
        $gatherer = new AnalyticsGatherer;

        $this->assertTrue($gatherer->supports('analytics'));
        $this->assertFalse($gatherer->supports('uptime'));
    }

    #[Test]
    public function returns_empty_without_cache(): void
    {
        $gatherer = new AnalyticsGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function returns_data_from_cache(): void
    {
        AnalyticsCache::create([
            'site_id' => $this->site->id,
            'date_range' => '28d',
            'start_date' => $this->periodStart->toDateString(),
            'end_date' => $this->periodEnd->toDateString(),
            'data' => [
                'overview' => [
                    'pageviews' => 5000,
                    'total_users' => 1200,
                    'new_users' => 800,
                    'bounce_rate' => 45.5,
                    'avg_session_duration' => 120.5,
                    'engagement_rate' => 65.0,
                    'sessions' => 1500,
                ],
                'users_over_time' => [
                    ['date' => '2026-02-01', 'users' => 40],
                    ['date' => '2026-02-02', 'users' => 55],
                ],
                'traffic_sources' => [
                    ['channel' => 'Organic', 'users' => 600, 'sessions' => 700],
                    ['channel' => 'Direct', 'users' => 300, 'sessions' => 350],
                ],
                'top_pages' => [
                    ['page' => '/', 'pageviews' => 1000],
                    ['page' => '/about', 'pageviews' => 500],
                ],
                'devices' => [
                    ['device' => 'mobile', 'sessions' => 800],
                    ['device' => 'desktop', 'sessions' => 700],
                ],
            ],
            'fetched_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);

        $gatherer = new AnalyticsGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertEquals(5000, $result['total_pageviews']);
        $this->assertEquals(1200, $result['total_users']);
        $this->assertEquals(800, $result['new_users']);
        $this->assertNotEmpty($result['traffic_sources']);
        $this->assertNotEmpty($result['top_pages']);
        $this->assertNotEmpty($result['devices']);
    }

    #[Test]
    public function handles_missing_overview_keys_gracefully(): void
    {
        AnalyticsCache::create([
            'site_id' => $this->site->id,
            'date_range' => '28d',
            'start_date' => $this->periodStart->toDateString(),
            'end_date' => $this->periodEnd->toDateString(),
            'data' => [
                'overview' => [
                    'pageviews' => 100,
                ],
            ],
            'fetched_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);

        $gatherer = new AnalyticsGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertEquals(100, $result['total_pageviews']);
        $this->assertArrayHasKey('total_users', $result);
    }
}
