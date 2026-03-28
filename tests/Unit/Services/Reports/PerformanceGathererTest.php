<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Reports;

use App\Models\PerformanceMonitor;
use App\Models\PerformanceTest;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\Sections\PerformanceGatherer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PerformanceGathererTest extends TestCase
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
            'performance_avg_desktop' => 88.0,
            'performance_avg_mobile' => 72.0,
            'analytics_users' => null,
            'analytics_sessions' => null,
            'analytics_pageviews' => null,
            'search_console_clicks' => null,
            'search_console_impressions' => null,
            'search_console_avg_position' => null,
            'cloudflare_requests' => null,
            'cloudflare_bandwidth_bytes' => null,
            'cloudflare_cache_hit_ratio' => null,
        ], $overrides));
    }

    #[Test]
    public function supports_performance_section(): void
    {
        $gatherer = new PerformanceGatherer;

        $this->assertTrue($gatherer->supports('performance'));
        $this->assertFalse($gatherer->supports('backups'));
    }

    #[Test]
    public function returns_empty_array_without_monitor(): void
    {
        $gatherer = new PerformanceGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function returns_score_keys_with_monitor(): void
    {
        $monitor = PerformanceMonitor::create([
            'site_id' => $this->site->id,
            'is_active' => true,
            'latest_mobile_score' => 75,
            'latest_desktop_score' => 90,
        ]);

        // The gatherer resolves latestMobileTest / latestDesktopTest relationships —
        // create completed tests so the gatherer returns data instead of [].
        PerformanceTest::factory()->create([
            'site_id' => $this->site->id,
            'performance_monitor_id' => $monitor->id,
            'device' => 'mobile',
            'status' => 'completed',
            'performance_score' => 75,
            'tested_at' => now()->subDays(1),
        ]);
        PerformanceTest::factory()->create([
            'site_id' => $this->site->id,
            'performance_monitor_id' => $monitor->id,
            'device' => 'desktop',
            'status' => 'completed',
            'performance_score' => 90,
            'tested_at' => now()->subDays(1),
        ]);

        $gatherer = new PerformanceGatherer;
        $result = $gatherer->gather(
            $this->site->fresh(), $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertArrayHasKey('mobile_score', $result);
        $this->assertArrayHasKey('desktop_score', $result);
        $this->assertArrayHasKey('mobile_trend', $result);
        $this->assertArrayHasKey('desktop_trend', $result);
    }
}
