<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Report;
use App\Models\Site;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use App\Services\Reports\ReportSendGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SPEC §12.3 report safety barriers. A clean report sends; a report generated
 * while the site is in trouble (open incident, site down) or with an out-of-range
 * headline value (uptime < 99%) is HELD, with a human-readable reason.
 */
class ReportSendGateTest extends TestCase
{
    use RefreshDatabase;

    private ReportSendGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new ReportSendGate;
    }

    private function reportFor(Site $site, array $data = []): Report
    {
        return Report::factory()->create([
            'site_id' => $site->id,
            'data_snapshot' => array_merge(['uptime' => ['uptime_percentage' => 100.0]], $data),
        ]);
    }

    public function test_a_clean_report_is_allowed_to_send(): void
    {
        $site = Site::factory()->create(['is_up' => true]);

        $result = $this->gate->evaluate($this->reportFor($site));

        $this->assertTrue($result['send']);
        $this->assertSame([], $result['reasons']);
    }

    public function test_an_open_uptime_incident_holds_the_report(): void
    {
        $site = Site::factory()->create(['is_up' => true]);
        $monitor = UptimeMonitor::factory()->create(['site_id' => $site->id]);
        UptimeIncident::factory()->create(['monitor_id' => $monitor->id, 'resolved_at' => null]);

        $result = $this->gate->evaluate($this->reportFor($site));

        $this->assertFalse($result['send']);
        $this->assertStringContainsStringIgnoringCase('incident', implode(' | ', $result['reasons']));
    }

    public function test_a_resolved_incident_does_not_hold_the_report(): void
    {
        $site = Site::factory()->create(['is_up' => true]);
        $monitor = UptimeMonitor::factory()->create(['site_id' => $site->id]);
        UptimeIncident::factory()->resolved()->create(['monitor_id' => $monitor->id]);

        $this->assertTrue($this->gate->evaluate($this->reportFor($site))['send']);
    }

    public function test_uptime_below_the_floor_holds_the_report(): void
    {
        $site = Site::factory()->create(['is_up' => true]);

        $result = $this->gate->evaluate(
            $this->reportFor($site, ['uptime' => ['uptime_percentage' => 98.4]])
        );

        $this->assertFalse($result['send']);
        $this->assertStringContainsStringIgnoringCase('uptime', implode(' | ', $result['reasons']));
    }

    public function test_a_site_that_is_currently_down_holds_the_report(): void
    {
        $site = Site::factory()->create(['is_up' => false]);

        $result = $this->gate->evaluate($this->reportFor($site));

        $this->assertFalse($result['send']);
        $this->assertStringContainsStringIgnoringCase('down', implode(' | ', $result['reasons']));
    }

    public function test_missing_uptime_data_does_not_by_itself_hold_a_healthy_site(): void
    {
        // No uptime_percentage key at all — Barrier 2 simply does not fire; a
        // healthy site with no open incident still sends. (Fail-safe covers only
        // genuine evaluation errors, not merely absent optional data.)
        $site = Site::factory()->create(['is_up' => true]);
        $report = Report::factory()->create(['site_id' => $site->id, 'data_snapshot' => []]);

        $this->assertTrue($this->gate->evaluate($report)['send']);
    }
}
