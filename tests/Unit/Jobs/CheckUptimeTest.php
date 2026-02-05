<?php

namespace Tests\Unit\Jobs;

use App\Jobs\CheckUptime;
use App\Models\MaintenanceWindow;
use App\Models\UptimeCheck;
use App\Models\UptimeMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class CheckUptimeTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    /**
     * Create a UptimeMonitor directly (bypassing the factory which has mismatched column names).
     */
    private function createMonitor(array $overrides = []): UptimeMonitor
    {
        $site = $overrides['site_id'] ?? null;
        if (!$site) {
            $site = $this->createSite();
            $overrides['site_id'] = $site->id;
        }

        $defaults = [
            'url' => 'https://example.com',
            'type' => 'http',
            'http_method' => 'GET',
            'interval' => 300,
            'timeout' => 30,
            'status' => 'active',
            'current_state' => 'up',
            'consecutive_failures' => 0,
            'alert_after_failures' => 3,
            'accepted_status_codes' => [200, 201, 301, 302],
            'follow_redirects' => true,
            'check_ssl' => false,
        ];

        return UptimeMonitor::create(array_merge($defaults, $overrides));
    }

    public function test_job_creates_uptime_check_record_on_success(): void
    {
        Http::fake([
            'https://example.com' => Http::response('OK', 200),
        ]);

        $monitor = $this->createMonitor();

        (new CheckUptime($monitor))->handle();

        $this->assertDatabaseHas('uptime_checks', [
            'monitor_id' => $monitor->id,
            'is_up' => true,
        ]);

        $this->assertEquals(1, UptimeCheck::where('monitor_id', $monitor->id)->count());
    }

    public function test_job_records_response_time(): void
    {
        Http::fake([
            'https://example.com' => Http::response('OK', 200),
        ]);

        $monitor = $this->createMonitor();

        (new CheckUptime($monitor))->handle();

        $check = UptimeCheck::where('monitor_id', $monitor->id)->first();

        $this->assertNotNull($check->response_time);
        $this->assertGreaterThanOrEqual(0, $check->response_time);
    }

    public function test_job_records_http_status_code(): void
    {
        Http::fake([
            'https://example.com' => Http::response('OK', 200),
        ]);

        $monitor = $this->createMonitor();

        (new CheckUptime($monitor))->handle();

        $check = UptimeCheck::where('monitor_id', $monitor->id)->first();

        $this->assertEquals(200, $check->status_code);
    }

    public function test_job_increments_consecutive_failures_on_failure(): void
    {
        Http::fake([
            'https://example.com' => Http::response('Server Error', 500),
        ]);

        $monitor = $this->createMonitor([
            'consecutive_failures' => 0,
            'current_state' => 'up',
            'accepted_status_codes' => [200],
        ]);

        (new CheckUptime($monitor))->handle();

        $monitor->refresh();

        $this->assertEquals(1, $monitor->consecutive_failures);
    }

    public function test_job_resets_consecutive_failures_on_success(): void
    {
        Http::fake([
            'https://example.com' => Http::response('OK', 200),
        ]);

        $monitor = $this->createMonitor([
            'consecutive_failures' => 5,
            'current_state' => 'down',
        ]);

        (new CheckUptime($monitor))->handle();

        $monitor->refresh();

        $this->assertEquals(0, $monitor->consecutive_failures);
    }

    public function test_job_updates_monitor_current_state(): void
    {
        Http::fake([
            'https://example.com' => Http::response('OK', 200),
        ]);

        $monitor = $this->createMonitor([
            'current_state' => 'down',
            'consecutive_failures' => 3,
        ]);

        (new CheckUptime($monitor))->handle();

        $monitor->refresh();

        $this->assertEquals('up', $monitor->current_state);
    }

    public function test_job_skips_check_during_maintenance_window(): void
    {
        $site = $this->createSite();

        $monitor = $this->createMonitor([
            'site_id' => $site->id,
        ]);

        MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'pause_uptime' => true,
            'scheduled_start_at' => now()->subHour(),
            'scheduled_end_at' => now()->addHour(),
            'actual_start_at' => now()->subHour(),
        ]);

        // Reload the relationship so MaintenanceService can find it
        $monitor->load('site.activeMaintenanceWindow');

        (new CheckUptime($monitor))->handle();

        // No uptime check should be created
        $this->assertEquals(0, UptimeCheck::where('monitor_id', $monitor->id)->count());

        // next_check_at should be updated
        $monitor->refresh();
        $this->assertNotNull($monitor->next_check_at);
    }

    public function test_job_handles_connection_timeout_gracefully(): void
    {
        Http::fake([
            'https://example.com' => function () {
                throw new \Illuminate\Http\Client\ConnectionException('Connection timed out');
            },
        ]);

        $monitor = $this->createMonitor();

        // Should not throw an exception
        (new CheckUptime($monitor))->handle();

        $check = UptimeCheck::where('monitor_id', $monitor->id)->first();

        $this->assertNotNull($check);
        $this->assertFalse($check->is_up);
        $this->assertNotNull($check->failure_reason);
    }
}
