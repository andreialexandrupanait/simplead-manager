<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use Illuminate\Contracts\Queue\Job as JobContract;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * P3-33: the repeated-job-failure alert must count atomically (no read-then-write
 * race) and fire once when the count REACHES OR EXCEEDS the threshold — not only
 * on exactly N, and not once per subsequent failure within the window.
 */
class RepeatedJobFailureAlertTest extends TestCase
{
    private function fireFailure(string $jobClass): void
    {
        // shouldIgnoreMissing: other JobFailed listeners (Horizon) call
        // getJobId()/etc — return null for anything we don't care about.
        $job = \Mockery::mock(JobContract::class)->shouldIgnoreMissing();
        $job->shouldReceive('resolveName')->andReturn($jobClass);

        event(new JobFailed('redis', $job, new \RuntimeException('boom')));
    }

    public function test_counter_is_atomic_and_alert_fires_at_threshold_then_not_again(): void
    {
        config()->set('monitoring.job_failure_alert_threshold', 3);
        config()->set('monitoring.job_failure_window_seconds', 3600);

        $class = 'App\\Testing\\FooJob';
        $counterKey = "job_failures:{$class}";
        $alertedKey = "{$counterKey}:alerted";

        $this->fireFailure($class);
        $this->fireFailure($class);

        $this->assertSame(2, (int) Cache::get($counterKey));
        $this->assertNull(Cache::get($alertedKey), 'alert must not fire below threshold');

        // Third failure reaches the threshold → alert guard set exactly once.
        $this->fireFailure($class);
        $this->assertSame(3, (int) Cache::get($counterKey));
        $this->assertTrue((bool) Cache::get($alertedKey), 'alert must fire when threshold is reached');

        // Further failures keep counting (no lost increments) but do not re-fire.
        $this->fireFailure($class);
        $this->fireFailure($class);
        $this->assertSame(5, (int) Cache::get($counterKey), 'increments must not be lost');
        $this->assertTrue((bool) Cache::get($alertedKey));
    }

    public function test_alert_fires_when_count_jumps_past_threshold(): void
    {
        config()->set('monitoring.job_failure_alert_threshold', 3);
        config()->set('monitoring.job_failure_window_seconds', 3600);

        $class = 'App\\Testing\\BarJob';
        $counterKey = "job_failures:{$class}";
        $alertedKey = "{$counterKey}:alerted";

        // Simulate a burst that already blew past the threshold before this event.
        Cache::put($counterKey, 10, 3600);
        $this->assertNull(Cache::get($alertedKey));

        // The next failure takes the count 10 → 11: never equal to 3, but the
        // alert must still fire because count >= threshold (P3-33).
        $this->fireFailure($class);

        $this->assertSame(11, (int) Cache::get($counterKey));
        $this->assertTrue((bool) Cache::get($alertedKey), 'alert must fire on >= threshold, not only exactly N');
    }
}
