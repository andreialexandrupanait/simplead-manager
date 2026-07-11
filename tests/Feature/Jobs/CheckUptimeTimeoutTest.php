<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\MonitorState;
use App\Jobs\CheckUptime;
use App\Models\Site;
use App\Models\UptimeMonitor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P0-17 (E-27): a monitor timeout >= the fixed worker timeout got the check
 * SIGKILLed before anything was recorded — a silent monitoring blackout with a
 * per-minute re-dispatch loop (next_check_at never advanced).
 */
class CheckUptimeTimeoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_job_timeout_is_derived_from_the_monitor_timeout_plus_buffer(): void
    {
        Queue::fake();

        $site = Site::factory()->create();
        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'timeout' => 90,
        ]);

        $job = new CheckUptime($monitor);

        // 90s request timeout + 15s buffer = 105s, comfortably above the old
        // hard 30s cap so a slow probe is never killed mid-check.
        $this->assertSame(105, $job->timeout);
    }

    public function test_job_timeout_stays_below_the_redis_retry_after(): void
    {
        Queue::fake();

        $site = Site::factory()->create();
        // Maximum user-settable monitor timeout.
        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'timeout' => 120,
        ]);

        $job = new CheckUptime($monitor);

        $retryAfter = config('queue.connections.redis.retry_after');

        $this->assertLessThan($retryAfter, $job->timeout);
    }

    public function test_failed_records_a_synthetic_failed_check_and_advances_next_check_at(): void
    {
        Queue::fake();

        $site = Site::factory()->create();
        $monitor = UptimeMonitor::factory()->create([
            'site_id' => $site->id,
            'timeout' => 90,
            'interval_minutes' => 5,
            // High threshold so no down alert/event fires — we only assert the
            // check row + state bookkeeping here.
            'alert_after_failures' => 5,
            'consecutive_failures' => 0,
            'current_state' => MonitorState::Up,
            'next_check_at' => now()->subMinute(),
        ]);

        (new CheckUptime($monitor))->failed(new \RuntimeException('timeout'));

        // A failed check row is recorded instead of a silent gap.
        $this->assertDatabaseHas('uptime_checks', [
            'monitor_id' => $monitor->id,
            'is_up' => false,
        ]);

        $fresh = $monitor->fresh();

        // next_check_at is advanced so the dispatcher does not relaunch every minute.
        $this->assertTrue($fresh->next_check_at->isFuture());
        $this->assertSame(1, $fresh->consecutive_failures);
    }
}
