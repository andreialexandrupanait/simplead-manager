<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RunPerformanceTest;
use App\Models\PerformanceMonitor;
use App\Models\PerformanceTest;
use App\Models\Site;
use App\Services\PageSpeedService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * P1-09: RunPerformanceTest writes 'running' rows before calling PageSpeed. A
 * SIGKILL'd worker leaves those rows 'running' forever and the UI polls them
 * endlessly. The job's failed() hook and a start-of-run sweep recover them; the
 * scheduled command is the backstop for hard kills that skip failed().
 */
class RunPerformanceTestRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // Site::factory dispatches FetchSiteFavicon (outbound HTTP).
    }

    public function test_failed_hook_marks_lingering_running_rows_as_failed(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        $monitor = PerformanceMonitor::create(['site_id' => $site->id]);

        $running = PerformanceTest::factory()->create([
            'site_id' => $site->id,
            'performance_monitor_id' => $monitor->id,
            'device' => 'mobile',
            'status' => 'running',
        ]);

        (new RunPerformanceTest($monitor))->failed(new RuntimeException('worker died'));

        $this->assertSame('failed', $running->fresh()->status);
        $this->assertStringContainsString('worker died', (string) $running->fresh()->error_message);
    }

    public function test_handle_sweeps_orphaned_running_rows_from_a_prior_attempt(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        $monitor = PerformanceMonitor::create(['site_id' => $site->id]);

        // Orphan left by a previous, dead attempt of this same unique job.
        $orphan = PerformanceTest::factory()->create([
            'site_id' => $site->id,
            'performance_monitor_id' => $monitor->id,
            'device' => 'mobile',
            'status' => 'running',
        ]);

        $pageSpeed = Mockery::mock(PageSpeedService::class);
        $pageSpeed->shouldReceive('analyze')->andReturn(['performance_score' => 92]);

        (new RunPerformanceTest($monitor, 'mobile'))->handle($pageSpeed);

        // The orphan is resolved and no 'running' rows survive the run.
        $this->assertSame('failed', $orphan->fresh()->status);
        $this->assertSame(
            0,
            PerformanceTest::where('performance_monitor_id', $monitor->id)->where('status', 'running')->count(),
        );
    }

    public function test_recover_command_fails_only_tests_older_than_the_threshold(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        $monitor = PerformanceMonitor::create(['site_id' => $site->id]);

        $stuck = PerformanceTest::factory()->create([
            'site_id' => $site->id,
            'performance_monitor_id' => $monitor->id,
            'status' => 'running',
        ]);
        PerformanceTest::where('id', $stuck->id)->update(['created_at' => now()->subMinutes(20)]);

        $fresh = PerformanceTest::factory()->create([
            'site_id' => $site->id,
            'performance_monitor_id' => $monitor->id,
            'status' => 'running',
        ]);
        PerformanceTest::where('id', $fresh->id)->update(['created_at' => now()->subMinutes(3)]);

        $this->artisan('performance:recover-stuck-tests')->assertSuccessful();

        $this->assertSame('failed', $stuck->fresh()->status);
        $this->assertSame('running', $fresh->fresh()->status);
    }

    public function test_recover_command_dry_run_changes_nothing(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        $monitor = PerformanceMonitor::create(['site_id' => $site->id]);

        $stuck = PerformanceTest::factory()->create([
            'site_id' => $site->id,
            'performance_monitor_id' => $monitor->id,
            'status' => 'running',
        ]);
        PerformanceTest::where('id', $stuck->id)->update(['created_at' => now()->subMinutes(30)]);

        $this->artisan('performance:recover-stuck-tests', ['--dry-run' => true])->assertSuccessful();

        $this->assertSame('running', $stuck->fresh()->status);
    }
}
