<?php

declare(strict_types=1);

namespace Tests\Feature\Dispatchers;

use App\Dispatchers\MonitoringDispatcher;
use App\Jobs\CheckUptime;
use App\Models\Site;
use App\Models\UptimeMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1-10 / E-28: a site without a `site_health_state` row was silently never
 * uptime-checked or synced because the dispatchers gated on that row via an
 * inner-join `whereHas`. The row is now auto-created on Site::created and the
 * dispatchers are LEFT-JOIN-safe (a missing row = monitorable).
 */
class HealthStateBackfillTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // swallow Site::created → FetchSiteFavicon (outbound HTTP)
    }

    public function test_creating_a_site_provisions_a_health_state_row(): void
    {
        $site = Site::factory()->create();

        $this->assertDatabaseHas('site_health_state', [
            'site_id' => $site->id,
            'circuit_state' => 'closed',
            'is_monitoring_disabled' => false,
        ]);
        $this->assertNotNull($site->healthState()->first());
    }

    public function test_site_without_health_state_row_is_still_uptime_checked(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        // Simulate a legacy site that predates the auto-provisioning: no row.
        $site->healthState()->delete();
        $this->assertDatabaseMissing('site_health_state', ['site_id' => $site->id]);

        UptimeMonitor::factory()->for($site)->create([
            'status' => 'active',
            'next_check_at' => now()->subMinute(),
        ]);

        (new MonitoringDispatcher)();

        Queue::assertPushed(
            CheckUptime::class,
            fn (CheckUptime $job) => $job->monitor->site_id === $site->id,
        );
    }

    public function test_open_circuit_still_suppresses_uptime_checks(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        $site->healthState()->update(['circuit_state' => 'open']);

        UptimeMonitor::factory()->for($site)->create([
            'status' => 'active',
            'next_check_at' => now()->subMinute(),
        ]);

        (new MonitoringDispatcher)();

        Queue::assertNotPushed(CheckUptime::class);
    }

    public function test_disabled_monitoring_still_suppresses_uptime_checks(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        $site->healthState()->update(['is_monitoring_disabled' => true]);

        UptimeMonitor::factory()->for($site)->create([
            'status' => 'active',
            'next_check_at' => now()->subMinute(),
        ]);

        (new MonitoringDispatcher)();

        Queue::assertNotPushed(CheckUptime::class);
    }
}
