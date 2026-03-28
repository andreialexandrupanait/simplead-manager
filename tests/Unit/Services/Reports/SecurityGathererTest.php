<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Reports;

use App\Models\SecurityIssue;
use App\Models\SecurityMonitor;
use App\Models\SecurityScan;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\SiteMonthlySnapshot;
use App\Services\ReportChartService;
use App\Services\Reports\Sections\SecurityGatherer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityGathererTest extends TestCase
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
            'security_avg_score' => 80.0,
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
    public function supports_security_section(): void
    {
        $gatherer = new SecurityGatherer;

        $this->assertTrue($gatherer->supports('security'));
        $this->assertFalse($gatherer->supports('performance'));
    }

    #[Test]
    public function returns_empty_without_security_monitor(): void
    {
        $gatherer = new SecurityGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    #[Test]
    public function returns_data_with_scan_in_period(): void
    {
        SecurityMonitor::create([
            'site_id' => $this->site->id,
            'is_active' => true,
            'interval_minutes' => 1440,
        ]);

        // total_issues is a computed accessor (critical + high + medium + low),
        // not a database column — do not pass it to the factory.
        SecurityScan::factory()->create([
            'site_id' => $this->site->id,
            'scanned_at' => $this->periodStart->copy()->addDays(15),
            'score' => 80,
            'critical_count' => 0,
            'high_count' => 1,
            'medium_count' => 2,
            'low_count' => 3,
        ]);

        $gatherer = new SecurityGatherer;
        $result = $gatherer->gather(
            $this->site->fresh(), $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertArrayHasKey('score', $result);
        $this->assertEquals(80, $result['score']);
        $this->assertEquals(1, $result['high_count']);
        $this->assertEquals(6, $result['total_issues']);
    }

    #[Test]
    public function includes_active_issues(): void
    {
        SecurityMonitor::create([
            'site_id' => $this->site->id,
            'is_active' => true,
            'interval_minutes' => 1440,
        ]);

        SecurityScan::factory()->create([
            'site_id' => $this->site->id,
            'scanned_at' => $this->periodStart->copy()->addDays(10),
            'score' => 70,
        ]);

        SecurityIssue::create([
            'site_id' => $this->site->id,
            'category' => 'hardening',
            'type' => 'debug_enabled',
            'severity' => 'high',
            'title' => 'Debug mode enabled',
            'recommendation' => 'Disable debug mode',
            'is_fixed' => false,
            'is_ignored' => false,
        ]);

        $gatherer = new SecurityGatherer;
        $result = $gatherer->gather(
            $this->site->fresh(), $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertNotEmpty($result['active_issues']);
        $this->assertEquals('Debug mode enabled', $result['active_issues'][0]['title']);
    }
}
