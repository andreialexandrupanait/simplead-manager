<?php

declare(strict_types=1);

namespace Tests\Feature\Dispatchers;

use App\Dispatchers\MonitoringDispatcher;
use App\Jobs\RunPerformanceTest;
use App\Models\PerformanceMonitor;
use App\Models\Site;
use App\Models\SiteHealthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P2-16: the plan-configured performance interval_minutes used to be a dead
 * knob — the scheduler recomputed next_test_at purely from the coarse
 * daily/weekly bucket. A custom interval must now drive the per-site due-time.
 */
class PerformanceIntervalDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_next_test_at_honors_a_custom_interval(): void
    {
        Carbon::setTestNow('2026-07-12 12:00:00');

        $monitor = new PerformanceMonitor([
            'frequency' => 'daily',
            'interval_minutes' => 4320, // 3 days — NOT the hardcoded +1 day
            'test_time' => '04:00',
        ]);

        $next = $monitor->calculateNextTestAt();

        $this->assertNotNull($next);
        $this->assertSame('2026-07-15 04:00:00', $next->format('Y-m-d H:i:s'));

        Carbon::setTestNow();
    }

    public function test_manual_frequency_has_no_next_test_at(): void
    {
        $monitor = new PerformanceMonitor(['frequency' => 'manual', 'interval_minutes' => 4320]);

        $this->assertNull($monitor->calculateNextTestAt());
    }

    public function test_interval_floor_is_enforced(): void
    {
        $monitor = new PerformanceMonitor([
            'frequency' => 'daily',
            'interval_minutes' => 5, // below the floor
            'test_time' => null,
        ]);

        $next = $monitor->calculateNextTestAt();

        $this->assertNotNull($next);
        $this->assertEqualsWithDelta(
            PerformanceMonitor::MIN_INTERVAL_MINUTES,
            (float) now()->diffInMinutes($next),
            1.0,
        );
    }

    public function test_site_with_custom_interval_is_only_dispatched_once_the_interval_elapses(): void
    {
        Queue::fake();

        $site = Site::factory()->create(['is_connected' => true]);
        SiteHealthState::firstOrCreate(['site_id' => $site->id]);

        $monitor = PerformanceMonitor::create([
            'site_id' => $site->id,
            'is_active' => true,
            'frequency' => 'daily',
            'interval_minutes' => 4320, // 3 days
            'last_tested_at' => now(),
            'next_test_at' => now()->addMinutes(4320), // due-time honors the interval
        ]);

        // Not yet due on the hardcoded daily cadence — must NOT dispatch.
        (new MonitoringDispatcher)();
        Queue::assertNotPushed(RunPerformanceTest::class);

        // Once the configured interval has elapsed the monitor becomes due.
        $monitor->update(['next_test_at' => now()->subMinute()]);
        (new MonitoringDispatcher)();
        Queue::assertPushed(RunPerformanceTest::class, fn (RunPerformanceTest $job) => $job->monitor->site_id === $site->id);
    }
}
