<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Reports;

use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\SiteMonthlySnapshot;
use App\Models\UptimeCheck;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use App\Services\ReportChartService;
use App\Services\Reports\Sections\UptimeGatherer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UptimeGathererTest extends TestCase
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
            'uptime_percentage' => 99.9,
            'uptime_avg_response_ms' => 280.0,
            'uptime_incidents_count' => 0,
            'uptime_down_checks' => 0,
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
            'search_console_clicks' => null,
            'search_console_impressions' => null,
            'search_console_avg_position' => null,
            'cloudflare_requests' => null,
            'cloudflare_bandwidth_bytes' => null,
            'cloudflare_cache_hit_ratio' => null,
        ], $overrides));
    }

    #[Test]
    public function supports_uptime_section(): void
    {
        $gatherer = new UptimeGatherer;

        $this->assertTrue($gatherer->supports('uptime'));
        $this->assertFalse($gatherer->supports('overview'));
    }

    #[Test]
    public function returns_unavailable_when_no_monitor(): void
    {
        $gatherer = new UptimeGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertArrayHasKey('available', $result);
        $this->assertFalse($result['available']);
    }

    #[Test]
    public function returns_data_with_monitor(): void
    {
        UptimeMonitor::factory()->for($this->site)->create([
            'uptime_30d' => 99.5,
        ]);

        $gatherer = new UptimeGatherer;
        $result = $gatherer->gather(
            $this->site->fresh(), $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertTrue($result['available']);
        $this->assertArrayHasKey('uptime_percentage', $result);
        $this->assertArrayHasKey('incidents_count', $result);
        $this->assertArrayHasKey('total_downtime_minutes', $result);
    }

    #[Test]
    public function counts_incidents_in_period(): void
    {
        $monitor = UptimeMonitor::factory()->for($this->site)->create();

        UptimeIncident::factory()->create([
            'monitor_id' => $monitor->id,
            'started_at' => $this->periodStart->copy()->addDays(5),
            'resolved_at' => $this->periodStart->copy()->addDays(5)->addMinutes(30),
            'status' => 'resolved',
            'cause' => 'Server error',
        ]);

        $gatherer = new UptimeGatherer;
        $result = $gatherer->gather(
            $this->site->fresh(), $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertEquals(1, $result['incidents_count']);
        $this->assertCount(1, $result['incidents']);
    }

    #[Test]
    public function calculates_average_response_time(): void
    {
        $monitor = UptimeMonitor::factory()->for($this->site)->create();

        UptimeCheck::create([
            'monitor_id' => $monitor->id,
            'checked_at' => $this->periodStart->copy()->addDays(1),
            'response_time' => 200,
            'is_up' => true,
            'status_code' => 200,
        ]);
        UptimeCheck::create([
            'monitor_id' => $monitor->id,
            'checked_at' => $this->periodStart->copy()->addDays(2),
            'response_time' => 400,
            'is_up' => true,
            'status_code' => 200,
        ]);

        $gatherer = new UptimeGatherer;
        $result = $gatherer->gather(
            $this->site->fresh(), $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertEquals(300, $result['avg_response_time']);
    }
}
