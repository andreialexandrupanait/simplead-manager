<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\MonitorState;
use App\Events\SiteRecovered;
use App\Events\SiteWentDown;
use App\Jobs\CheckUptime;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CheckUptimeTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private UptimeMonitor $monitor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();

        SiteHealthState::create([
            'site_id' => $this->site->id,
            'circuit_state' => 'closed',
        ]);

        $this->monitor = UptimeMonitor::factory()->up()->for($this->site)->create([
            'url' => 'https://example.com',
            'alert_after_failures' => 3,
        ]);
    }

    #[Test]
    public function site_stays_up_on_successful_check(): void
    {
        Http::fake(['https://example.com' => Http::response('OK', 200)]);

        (new CheckUptime($this->monitor))->handle();

        $monitor = $this->monitor->fresh();

        $this->assertEquals(MonitorState::Up, $monitor->current_state);
        $this->assertEquals(0, $monitor->consecutive_failures);

        $this->assertDatabaseHas('uptime_checks', [
            'monitor_id' => $this->monitor->id,
            'is_up' => true,
        ]);
    }

    #[Test]
    public function single_failure_increments_consecutive_failures(): void
    {
        Http::fake(['https://example.com' => Http::response('Error', 500)]);

        (new CheckUptime($this->monitor))->handle();

        $monitor = $this->monitor->fresh();

        $this->assertEquals(1, $monitor->consecutive_failures);

        $this->assertDatabaseHas('uptime_checks', [
            'monitor_id' => $this->monitor->id,
            'is_up' => false,
        ]);
    }

    #[Test]
    public function consecutive_failures_trigger_down_at_threshold(): void
    {
        // Two prior failures — one more will hit the threshold of 3
        $this->monitor->update(['consecutive_failures' => 2, 'alert_after_failures' => 3]);

        Http::fake(['https://example.com' => Http::response('Error', 500)]);

        (new CheckUptime($this->monitor))->handle();

        $monitor = $this->monitor->fresh();

        $this->assertEquals(MonitorState::Down, $monitor->current_state);
        $this->assertEquals(3, $monitor->consecutive_failures);
    }

    #[Test]
    public function site_went_down_event_dispatched_at_threshold(): void
    {
        Event::fake([SiteWentDown::class, SiteRecovered::class]);

        $this->monitor->update(['consecutive_failures' => 2, 'alert_after_failures' => 3]);

        Http::fake(['https://example.com' => Http::response('Error', 500)]);

        (new CheckUptime($this->monitor))->handle();

        Event::assertDispatched(SiteWentDown::class);
    }

    #[Test]
    public function site_went_down_event_not_dispatched_before_threshold(): void
    {
        Event::fake([SiteWentDown::class, SiteRecovered::class]);

        // Still at 0 failures — first failure should not dispatch the event
        Http::fake(['https://example.com' => Http::response('Error', 500)]);

        (new CheckUptime($this->monitor))->handle();

        Event::assertNotDispatched(SiteWentDown::class);
    }

    #[Test]
    public function recovery_transitions_to_up_and_resolves_incident(): void
    {
        $this->monitor->update([
            'current_state' => MonitorState::Down,
            'consecutive_failures' => 3,
        ]);

        UptimeIncident::factory()->for($this->monitor, 'monitor')->create([
            'status' => 'ongoing',
        ]);

        Http::fake(['https://example.com' => Http::response('OK', 200)]);

        (new CheckUptime($this->monitor))->handle();

        $monitor = $this->monitor->fresh();

        $this->assertEquals(MonitorState::Up, $monitor->current_state);
        $this->assertEquals(0, $monitor->consecutive_failures);

        $incident = UptimeIncident::where('monitor_id', $this->monitor->id)->first();

        $this->assertEquals('resolved', $incident->status);
        $this->assertNotNull($incident->resolved_at);
    }

    #[Test]
    public function site_recovered_event_dispatched(): void
    {
        Event::fake([SiteWentDown::class, SiteRecovered::class]);

        $this->monitor->update([
            'current_state' => MonitorState::Down,
            'consecutive_failures' => 3,
        ]);

        UptimeIncident::factory()->for($this->monitor, 'monitor')->create([
            'status' => 'ongoing',
        ]);

        Http::fake(['https://example.com' => Http::response('OK', 200)]);

        (new CheckUptime($this->monitor))->handle();

        Event::assertDispatched(SiteRecovered::class);
    }

    #[Test]
    public function incident_created_on_first_failure(): void
    {
        Http::fake(['https://example.com' => Http::response('Error', 500)]);

        (new CheckUptime($this->monitor))->handle();

        $this->assertDatabaseHas('uptime_incidents', [
            'monitor_id' => $this->monitor->id,
            'status' => 'ongoing',
        ]);
    }

    #[Test]
    public function site_model_synced_after_check(): void
    {
        Http::fake(['https://example.com' => Http::response('OK', 200)]);

        (new CheckUptime($this->monitor))->handle();

        $this->assertTrue($this->site->fresh()->is_up);
    }
}
