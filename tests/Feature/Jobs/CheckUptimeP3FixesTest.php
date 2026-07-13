<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\MonitorState;
use App\Jobs\AggregateUptimeWindows;
use App\Jobs\CheckUptime;
use App\Models\Site;
use App\Models\UptimeCheck;
use App\Models\UptimeMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P3-13/14/15/16/18 uptime & health-score hardening.
 */
class CheckUptimeP3FixesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-07-13 12:00:00');
        // Site::created dispatches FetchSiteFavicon (a queued job that makes an
        // HTTP request). Fake the queue so it never runs and pollutes the HTTP
        // request assertions below.
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---- P3-16: keyword monitor must issue a GET (body present), not HEAD ----

    public function test_keyword_monitor_issues_a_get_not_head(): void
    {
        Http::fake([
            '*' => Http::response('<html>welcome home</html>', 200),
        ]);

        $site = Site::factory()->create();
        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'type' => 'keyword',
            'http_method' => 'HEAD', // deliberately HEAD to prove it is overridden
            'keyword' => 'welcome',
            'keyword_type' => 'exists',
            'accepted_status_codes' => [200],
        ]);

        (new CheckUptime($monitor))->handle();

        Http::assertSent(fn ($request) => $request->method() === 'GET');

        // A GET returns a body, so the keyword is actually found → check is up.
        $check = UptimeCheck::where('monitor_id', $monitor->id)->latest('id')->first();
        $this->assertNotNull($check);
        $this->assertTrue($check->is_up);
        $this->assertTrue($check->keyword_found);
    }

    // ---- P3-16: ping monitor does a reachability probe, not a false down ----

    public function test_ping_monitor_uses_tcp_probe_not_http(): void
    {
        Http::fake(); // any HTTP call would be a bug

        $site = Site::factory()->create();
        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'type' => 'ping',
            'keyword' => null,
            'url' => 'https://example.com',
        ]);

        $job = new class($monitor) extends CheckUptime
        {
            protected function tcpProbe(string $host, int $port, int $timeout): bool
            {
                return true; // simulate a reachable host
            }
        };

        $job->handle();

        Http::assertNothingSent();

        $check = UptimeCheck::where('monitor_id', $monitor->id)->latest('id')->first();
        $this->assertNotNull($check);
        $this->assertTrue($check->is_up, 'A reachable ping monitor must be up, not a keyword-vs-empty-body false down.');
    }

    public function test_ping_monitor_records_down_when_unreachable(): void
    {
        Http::fake();

        $site = Site::factory()->create();
        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'type' => 'ping',
            'url' => 'https://example.com',
            'alert_after_failures' => 1,
        ]);

        $job = new class($monitor) extends CheckUptime
        {
            protected function tcpProbe(string $host, int $port, int $timeout): bool
            {
                return false;
            }
        };

        $job->handle();

        Http::assertNothingSent();

        $check = UptimeCheck::where('monitor_id', $monitor->id)->latest('id')->first();
        $this->assertNotNull($check);
        $this->assertFalse($check->is_up);
    }

    // ---- P3-15: a degraded check leaves is_up true; a down check flips it ----

    public function test_degraded_check_keeps_site_is_up_true(): void
    {
        Http::fake([
            '*' => Http::response('error', 500),
        ]);

        $site = Site::factory()->create(['is_up' => true]);
        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'type' => 'http',
            'keyword' => null,
            'consecutive_failures' => 0,
            'current_state' => MonitorState::Up,
            'alert_after_failures' => 3, // first failure = degraded, not down
            'accepted_status_codes' => [200],
        ]);

        (new CheckUptime($monitor))->handle();

        $this->assertSame(MonitorState::Degraded, $monitor->fresh()->current_state);
        $this->assertTrue($site->fresh()->is_up, 'A single degraded check must not flip is_up to false.');
    }

    public function test_down_check_sets_site_is_up_false(): void
    {
        Http::fake([
            '*' => Http::response('error', 500),
        ]);

        $site = Site::factory()->create(['is_up' => true]);
        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'type' => 'http',
            'keyword' => null,
            'consecutive_failures' => 0,
            'current_state' => MonitorState::Up,
            'alert_after_failures' => 1, // first failure = down
            'accepted_status_codes' => [200],
        ]);

        (new CheckUptime($monitor))->handle();

        $this->assertSame(MonitorState::Down, $monitor->fresh()->current_state);
        $this->assertFalse($site->fresh()->is_up);
    }

    // ---- P3-18: maintenance window skip still advances next_check_at ----

    public function test_maintenance_window_skip_advances_next_check_at(): void
    {
        Http::fake();

        $site = Site::factory()->create();
        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'interval_minutes' => 5,
            'next_check_at' => now()->subMinute(),
            'maintenance_starts_at' => now()->subMinutes(10),
            'maintenance_ends_at' => now()->addMinutes(30),
        ]);

        (new CheckUptime($monitor))->handle();

        Http::assertNothingSent();

        $fresh = $monitor->fresh();
        $this->assertTrue($fresh->next_check_at->isFuture());
        // No check should have been recorded during maintenance.
        $this->assertSame(0, UptimeCheck::where('monitor_id', $monitor->id)->count());
    }

    // ---- P3-13: uptime check refreshes a null health_score ----

    public function test_uptime_check_refreshes_null_health_score(): void
    {
        Http::fake([
            '*' => Http::response('ok', 200),
        ]);

        $site = Site::factory()->create([
            'is_connected' => true,
            'health_score' => null,
        ]);
        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'type' => 'http',
            'keyword' => null,
            'accepted_status_codes' => [200],
        ]);

        (new CheckUptime($monitor))->handle();

        $this->assertNotNull($site->fresh()->health_score, 'A completed uptime check must refresh the health score.');
    }

    // ---- P3-14: 365d window is not recomputed inline; aggregate does it ----

    public function test_check_does_not_recompute_365d_inline(): void
    {
        Http::fake([
            '*' => Http::response('ok', 200),
        ]);

        $site = Site::factory()->create();
        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'type' => 'http',
            'keyword' => null,
            'accepted_status_codes' => [200],
            'uptime_365d' => 42.0, // sentinel — must survive an inline check
        ]);

        (new CheckUptime($monitor))->handle();

        $this->assertSame(
            42.0,
            (float) $monitor->fresh()->uptime_365d,
            'uptime_365d must not be recomputed in the per-check hot path.'
        );
    }

    public function test_aggregate_recomputes_365d_from_available_data(): void
    {
        $site = Site::factory()->create();
        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'uptime_365d' => null,
        ]);

        // 3 up, 1 down within the retained window → 75%.
        foreach ([true, true, true, false] as $i => $isUp) {
            UptimeCheck::create([
                'monitor_id' => $monitor->id,
                'is_up' => $isUp,
                'response_time' => 100,
                'status_code' => $isUp ? 200 : 500,
                'location' => 'primary',
                'checked_at' => now()->subDays($i + 1),
            ]);
        }

        (new AggregateUptimeWindows)->handle(app(\App\Services\RetentionPolicyService::class));

        $this->assertSame(75.0, (float) $monitor->fresh()->uptime_365d);
    }

    // ---- P3-17: a long (256–2048 char) URL persists without a DB 500 ----

    public function test_monitor_with_long_url_saves_without_error(): void
    {
        $site = Site::factory()->create();

        $longUrl = 'https://example.com/'.str_repeat('a', 1980); // ~2000 chars
        $this->assertGreaterThan(255, strlen($longUrl));
        $this->assertLessThanOrEqual(2048, strlen($longUrl));

        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'url' => $longUrl,
        ]);

        $this->assertSame($longUrl, $monitor->fresh()->url);
    }
}
