<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\CheckUptime;
use App\Models\Site;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SPEC §5.1 — "Regula anti-fals-pozitiv: două eșecuri consecutive înainte de a
 * deschide un incident, ideal din două locații. Altfel o sincopă de rețea pe VPS
 * declanșează downtime fals pe toată flota."
 *
 * Every Manager check leaves from one box, so its uplink stuttering used to look
 * exactly like the whole fleet going down — and an incident was opened on the
 * FIRST failed probe.
 */
class UptimeSecondLocationTest extends TestCase
{
    use RefreshDatabase;

    private const PROBE = 'https://probe.example.test';

    private function monitor(int $consecutiveFailures = 0): UptimeMonitor
    {
        $site = Site::factory()->create(['url' => 'https://target.test']);

        return UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'url' => 'https://target.test',
            'status' => 'active',
            'type' => 'http',
            'consecutive_failures' => $consecutiveFailures,
            'alert_after_failures' => 2,
        ]);
    }

    private function enableSecondLocation(): void
    {
        Config::set('monitoring.second_location.url', self::PROBE);
        Config::set('monitoring.second_location.secret', 'shared-secret');
    }

    /** Drive the failure path directly — the HTTP check itself is covered elsewhere. */
    private function driveFailure(UptimeMonitor $monitor): void
    {
        $job = new CheckUptime($monitor);

        (function () {
            $this->handleFailure(['is_up' => false, 'failure_reason' => 'HTTP 502', 'response_time' => null, 'status_code' => 502, 'keyword_found' => null]);
        })->call($job);
    }

    public function test_a_single_failure_does_not_open_an_incident(): void
    {
        $monitor = $this->monitor(consecutiveFailures: 1);

        $this->driveFailure($monitor);

        $this->assertDatabaseCount('uptime_incidents', 0);
    }

    public function test_two_failures_open_an_incident_when_there_is_no_second_location(): void
    {
        $monitor = $this->monitor(consecutiveFailures: 2);

        $this->driveFailure($monitor);

        $this->assertDatabaseCount('uptime_incidents', 1);
    }

    public function test_no_incident_when_the_second_location_can_reach_the_site(): void
    {
        $this->enableSecondLocation();
        Http::fake([self::PROBE.'/probe*' => Http::response(['ok' => true, 'status' => 200, 'ms' => 74, 'colo' => 'FRA'], 200)]);

        $monitor = $this->monitor(consecutiveFailures: 3);

        $this->driveFailure($monitor);

        // Cloudflare's edge reached the site — what is broken is the path from
        // our box, not the client's site.
        $this->assertDatabaseCount('uptime_incidents', 0);
    }

    public function test_incident_is_opened_and_attributed_when_both_locations_agree(): void
    {
        $this->enableSecondLocation();
        Http::fake([self::PROBE.'/probe*' => Http::response(['ok' => false, 'status' => null, 'ms' => 9000, 'colo' => 'FRA', 'error' => 'timeout'], 200)]);

        $monitor = $this->monitor(consecutiveFailures: 2);

        $this->driveFailure($monitor);

        $incident = UptimeIncident::firstOrFail();
        $this->assertStringContainsString('FRA', (string) $incident->cause, 'The corroboration belongs in the record.');
    }

    public function test_an_unreachable_probe_never_suppresses_an_incident(): void
    {
        $this->enableSecondLocation();
        Http::fake([self::PROBE.'/probe*' => Http::response('gateway down', 502)]);

        $monitor = $this->monitor(consecutiveFailures: 2);

        $this->driveFailure($monitor);

        // Silence from the probe is not evidence the site is fine.
        $this->assertDatabaseCount('uptime_incidents', 1);
    }

    public function test_a_probe_that_throws_never_suppresses_an_incident(): void
    {
        $this->enableSecondLocation();
        Http::fake(fn () => throw new \RuntimeException('dns failure'));

        $monitor = $this->monitor(consecutiveFailures: 2);

        $this->driveFailure($monitor);

        $this->assertDatabaseCount('uptime_incidents', 1);
    }

    public function test_the_probe_is_not_called_at_all_when_unconfigured(): void
    {
        Config::set('monitoring.second_location.url', null);
        Config::set('monitoring.second_location.secret', null);
        Http::preventStrayRequests();

        $monitor = $this->monitor(consecutiveFailures: 2);

        $this->driveFailure($monitor);

        $this->assertDatabaseCount('uptime_incidents', 1);
    }

    public function test_an_existing_incident_is_not_duplicated(): void
    {
        $monitor = $this->monitor(consecutiveFailures: 5);
        $monitor->incidents()->create(['status' => 'ongoing', 'cause' => 'HTTP 500', 'started_at' => now()->subHour()]);

        $this->driveFailure($monitor);

        $this->assertDatabaseCount('uptime_incidents', 1);
    }
}
