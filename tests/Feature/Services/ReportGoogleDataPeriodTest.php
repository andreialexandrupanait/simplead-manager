<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\AnalyticsCache;
use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Services\ReportChartService;
use App\Services\Reports\Sections\AnalyticsGatherer;
use App\Services\Reports\Sections\SearchConsoleGatherer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P2-03: reports embedded Google Analytics / Search Console data from a fixed rolling
 * 28-day cache regardless of the report's actual period and with no staleness check.
 * The gatherers now prefer a cached window that covers the report period, and flag +
 * label data that does not (rather than presenting a wrong window as current).
 */
class ReportGoogleDataPeriodTest extends TestCase
{
    use RefreshDatabase;

    private Carbon $periodStart;

    private Carbon $periodEnd;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        // Report generated shortly after the reporting month (June 2026).
        Carbon::setTestNow(Carbon::parse('2026-07-03 12:00:00'));
        $this->periodStart = Carbon::create(2026, 6, 1)->startOfDay();
        $this->periodEnd = Carbon::create(2026, 6, 30)->endOfDay();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function gatherAnalytics(Site $site): array
    {
        return (new AnalyticsGatherer)->gather(
            $site,
            $this->periodStart,
            $this->periodEnd,
            null,
            null,
            new ReportChartService,
            'en',
        );
    }

    public function test_analytics_prefers_a_cache_covering_the_report_period(): void
    {
        $site = Site::factory()->create();

        // Non-covering rolling 28d cache (would have been used before the fix).
        AnalyticsCache::create([
            'site_id' => $site->id,
            'date_range' => '28d',
            'start_date' => '2026-06-05',
            'end_date' => '2026-07-02',
            'data' => ['overview' => ['pageviews' => 111]],
            'fetched_at' => Carbon::parse('2026-07-02 06:00:00'),
            'expires_at' => Carbon::parse('2026-07-02 12:00:00'),
        ]);

        // Covering window that matches the report period.
        AnalyticsCache::create([
            'site_id' => $site->id,
            'date_range' => 'custom',
            'start_date' => '2026-05-31',
            'end_date' => '2026-07-01',
            'data' => ['overview' => ['pageviews' => 999]],
            'fetched_at' => Carbon::parse('2026-07-02 07:00:00'),
            'expires_at' => Carbon::parse('2026-07-02 13:00:00'),
        ]);

        $result = $this->gatherAnalytics($site);

        $this->assertSame(999, $result['total_pageviews'], 'The period-covering cache must be used.');
        $this->assertTrue($result['data_covers_period']);
        $this->assertFalse($result['data_is_stale']);
    }

    public function test_analytics_flags_and_labels_a_non_covering_fallback(): void
    {
        $site = Site::factory()->create();

        AnalyticsCache::create([
            'site_id' => $site->id,
            'date_range' => '28d',
            'start_date' => '2026-06-05',
            'end_date' => '2026-07-02',
            'data' => ['overview' => ['pageviews' => 111]],
            'fetched_at' => Carbon::parse('2026-07-02 06:00:00'),
            'expires_at' => Carbon::parse('2026-07-02 12:00:00'),
        ]);

        $result = $this->gatherAnalytics($site);

        // Falls back to the only cache, but flags it and labels its ACTUAL window,
        // not the report period.
        $this->assertSame(111, $result['total_pageviews']);
        $this->assertFalse($result['data_covers_period']);
        $this->assertTrue($result['data_is_stale']);
        $this->assertSame('05.06.2026', $result['data_period_start']);
        $this->assertSame('02.07.2026', $result['data_period_end']);
    }

    public function test_search_console_prefers_covering_window_and_flags_fallback(): void
    {
        $site = Site::factory()->create();

        // Covering window.
        SearchConsoleCache::create([
            'site_id' => $site->id,
            'date_range' => 'custom',
            'start_date' => '2026-05-31',
            'end_date' => '2026-07-01',
            'data_type' => 'overview',
            'data' => ['clicks' => 500, 'impressions' => 9000, 'ctr' => 5, 'position' => 3],
            'fetched_at' => Carbon::parse('2026-07-02 07:00:00'),
            'expires_at' => Carbon::parse('2026-07-02 13:00:00'),
        ]);

        $result = (new SearchConsoleGatherer)->gather(
            $site,
            $this->periodStart,
            $this->periodEnd,
            null,
            null,
            new ReportChartService,
            'en',
        );

        $this->assertTrue($result['data_covers_period']);
        $this->assertFalse($result['data_is_stale']);
        $this->assertSame(500, $result['overview']['total_clicks']);
    }

    public function test_search_console_falls_back_to_28d_and_flags_stale(): void
    {
        $site = Site::factory()->create();

        SearchConsoleCache::create([
            'site_id' => $site->id,
            'date_range' => '28d',
            'start_date' => '2026-06-05',
            'end_date' => '2026-07-02',
            'data_type' => 'overview',
            'data' => ['clicks' => 42, 'impressions' => 1000, 'ctr' => 4, 'position' => 8],
            'fetched_at' => Carbon::parse('2026-07-02 06:00:00'),
            'expires_at' => Carbon::parse('2026-07-02 12:00:00'),
        ]);

        $result = (new SearchConsoleGatherer)->gather(
            $site,
            $this->periodStart,
            $this->periodEnd,
            null,
            null,
            new ReportChartService,
            'en',
        );

        $this->assertFalse($result['data_covers_period']);
        $this->assertTrue($result['data_is_stale']);
        $this->assertSame('05.06.2026', $result['data_period_start']);
    }
}
