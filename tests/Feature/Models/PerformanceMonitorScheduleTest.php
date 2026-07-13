<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\PerformanceMonitor;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * P3-19: a weekly performance monitor must honor its configured day_of_week
 * instead of drifting purely by interval_minutes.
 */
class PerformanceMonitorScheduleTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_weekly_monitor_next_run_lands_on_configured_day_of_week(): void
    {
        // Wednesday 2026-07-15.
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 10, 0, 0));

        $site = Site::factory()->create();
        $monitor = PerformanceMonitor::create([
            'site_id' => $site->id,
            'frequency' => 'weekly',
            'day_of_week' => Carbon::MONDAY, // 1
            'test_time' => '04:00',
        ]);

        $next = $monitor->calculateNextTestAt();

        $this->assertNotNull($next);
        $this->assertSame(Carbon::MONDAY, $next->dayOfWeek, 'weekly monitor must land on Monday');
        $this->assertSame('04:00', $next->format('H:i'));
        $this->assertTrue($next->greaterThan(Carbon::getTestNow()));
    }

    public function test_weekly_monitor_without_day_of_week_still_schedules(): void
    {
        Carbon::setTestNow(Carbon::create(2026, 7, 15, 10, 0, 0));

        $site = Site::factory()->create();
        $monitor = PerformanceMonitor::create([
            'site_id' => $site->id,
            'frequency' => 'weekly',
            'day_of_week' => null,
        ]);

        $this->assertNotNull($monitor->calculateNextTestAt());
    }
}
