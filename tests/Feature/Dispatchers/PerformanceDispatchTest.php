<?php

declare(strict_types=1);

namespace Tests\Feature\Dispatchers;

use App\Dispatchers\MonitoringDispatcher;
use App\Jobs\RunPerformanceTest;
use App\Models\PerformanceMonitor;
use App\Models\Site;
use App\Models\SiteHealthState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * E-15: scheduled performance tests never dispatched (next_test_at was written
 * but nothing consumed it). The monitoring dispatcher must now run due tests.
 */
class PerformanceDispatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_due_performance_monitor_is_dispatched(): void
    {
        Queue::fake();

        $site = Site::factory()->create(['is_connected' => true]);
        SiteHealthState::create(['site_id' => $site->id]); // circuit closed, monitoring enabled (defaults)
        PerformanceMonitor::create(['site_id' => $site->id]); // is_active, next_test_at null (defaults)

        (new MonitoringDispatcher)();

        Queue::assertPushed(RunPerformanceTest::class, fn ($job) => $job->monitor->site_id === $site->id);
    }

    public function test_inactive_performance_monitor_is_not_dispatched(): void
    {
        Queue::fake();

        $site = Site::factory()->create(['is_connected' => true]);
        SiteHealthState::create(['site_id' => $site->id]);
        PerformanceMonitor::create(['site_id' => $site->id, 'is_active' => false]);

        (new MonitoringDispatcher)();

        Queue::assertNotPushed(RunPerformanceTest::class);
    }
}
