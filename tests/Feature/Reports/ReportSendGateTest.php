<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Report;
use App\Models\Site;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use App\Services\Reports\BaseReportSectionGatherer;
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

    /**
     * SPEC §12.3's other out-of-range case: "salt de trafic de câteva ori". It was
     * deferred for want of prior-period data, which the snapshot does in fact carry
     * — a report that presents a tripling as growth is worse than a late one, since
     * a 3x month-on-month move is nearly always a measurement that changed.
     */
    public function test_a_traffic_multiple_holds_the_report(): void
    {
        $site = Site::factory()->create(['is_up' => true]);

        $result = $this->gate->evaluate($this->reportFor($site, [
            'analytics' => ['users_current' => 9000, 'users_previous' => 1200],
        ]));

        $this->assertFalse($result['send']);
        $this->assertStringContainsStringIgnoringCase('users moved', implode(' | ', $result['reasons']));
    }

    public function test_a_traffic_collapse_holds_the_report_too(): void
    {
        $site = Site::factory()->create(['is_up' => true]);

        // A collapse is the same broken-measurement signal inverted, and just as
        // wrong to send unreviewed.
        $result = $this->gate->evaluate($this->reportFor($site, [
            'analytics' => ['pageviews_current' => 400, 'pageviews_previous' => 5000],
        ]));

        $this->assertFalse($result['send']);
        $this->assertStringContainsStringIgnoringCase('pageviews moved', implode(' | ', $result['reasons']));
    }

    public function test_ordinary_growth_still_sends(): void
    {
        $site = Site::factory()->create(['is_up' => true]);

        $result = $this->gate->evaluate($this->reportFor($site, [
            'analytics' => ['users_current' => 1500, 'users_previous' => 1200],
        ]));

        $this->assertTrue($result['send']);
    }

    /**
     * Without a floor, a quiet site would hold its report every month: 4 users
     * becoming 20 is a five-fold rise and means nothing.
     */
    public function test_small_numbers_do_not_hold_the_report(): void
    {
        $site = Site::factory()->create(['is_up' => true]);

        $result = $this->gate->evaluate($this->reportFor($site, [
            'analytics' => ['users_current' => 20, 'users_previous' => 4],
        ]));

        $this->assertTrue($result['send']);
    }

    public function test_a_site_with_no_previous_period_is_not_held(): void
    {
        $site = Site::factory()->create(['is_up' => true]);

        // First month on the platform: nothing to compare against is not an anomaly.
        $result = $this->gate->evaluate($this->reportFor($site, [
            'analytics' => ['users_current' => 9000, 'users_previous' => null],
        ]));

        $this->assertTrue($result['send']);
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

    // ── Barrier 3: a section empty because its integration failed (§12.3) ──

    public function test_a_configured_integration_that_returned_nothing_holds_the_report(): void
    {
        $site = Site::factory()->create(['is_up' => true]);

        $report = $this->reportFor($site, [
            'analytics' => [BaseReportSectionGatherer::INTEGRATION_KEY => [
                'section' => 'analytics',
                'configured' => true,
                'ok' => false,
                'reason' => 'no Analytics data cached for the report period',
            ]],
        ]);

        $result = $this->gate->evaluate($report);

        $this->assertFalse($result['send'], 'A hole where a paid-for section should be must not go out silently.');
        $this->assertStringContainsString('analytics', implode(' | ', $result['reasons']));
    }

    public function test_an_integration_that_was_never_configured_does_not_hold_the_report(): void
    {
        $site = Site::factory()->create(['is_up' => true]);

        // Cloudflare simply is not set up for this client — the section is absent
        // by design, which is not a failure.
        $report = $this->reportFor($site, [
            'cloudflare' => [BaseReportSectionGatherer::INTEGRATION_KEY => [
                'section' => 'cloudflare',
                'configured' => false,
                'ok' => true,
                'reason' => null,
            ]],
        ]);

        $this->assertTrue($this->gate->evaluate($report)['send']);
    }

    public function test_sections_without_an_integration_marker_are_ignored(): void
    {
        $site = Site::factory()->create(['is_up' => true]);

        // Locally-sourced sections (updates, backups…) carry no marker and can
        // never trip barrier 3.
        $report = $this->reportFor($site, ['updates' => ['applied' => 0], 'backups' => []]);

        $this->assertTrue($this->gate->evaluate($report)['send']);
    }

    public function test_every_failed_section_is_named(): void
    {
        $site = Site::factory()->create(['is_up' => true]);

        $report = $this->reportFor($site, [
            'analytics' => [BaseReportSectionGatherer::INTEGRATION_KEY => [
                'section' => 'analytics', 'configured' => true, 'ok' => false, 'reason' => 'GA4 fetch failed',
            ]],
            'search_console' => [BaseReportSectionGatherer::INTEGRATION_KEY => [
                'section' => 'search_console', 'configured' => true, 'ok' => false, 'reason' => 'GSC fetch failed',
            ]],
        ]);

        $reasons = implode(' | ', $this->gate->evaluate($report)['reasons']);

        $this->assertStringContainsString('analytics', $reasons);
        $this->assertStringContainsString('search_console', $reasons);
    }
}
