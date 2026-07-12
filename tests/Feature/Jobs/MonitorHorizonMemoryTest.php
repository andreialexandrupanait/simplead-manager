<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\MonitorHorizonMemory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * P1-06: the near-limit OOM alert. The job reads its container cgroup memory and
 * raises a critical alert when usage crosses the threshold, so an operator is
 * warned before a cgroup OOM SIGKILL can kill a worker mid-backup/restore.
 */
class MonitorHorizonMemoryTest extends TestCase
{
    use RefreshDatabase;

    private const CACHE_KEY = 'horizon_memory_pressure_notified';

    public function test_alerts_once_when_usage_crosses_the_threshold(): void
    {
        Cache::forget(self::CACHE_KEY);

        $this->makeJob(0.92)->handle();

        $this->assertTrue(Cache::has(self::CACHE_KEY), 'Expected a memory-pressure alert to be recorded.');
    }

    public function test_stays_silent_and_clears_flag_below_the_threshold(): void
    {
        Cache::put(self::CACHE_KEY, true, 1800);

        $this->makeJob(0.40)->handle();

        $this->assertFalse(Cache::has(self::CACHE_KEY), 'Below threshold the alert flag must be cleared.');
    }

    public function test_no_op_when_cgroup_is_unreadable(): void
    {
        Cache::forget(self::CACHE_KEY);

        $this->makeJob(null)->handle();

        $this->assertFalse(Cache::has(self::CACHE_KEY));
    }

    private function makeJob(?float $ratio): MonitorHorizonMemory
    {
        return new class($ratio) extends MonitorHorizonMemory
        {
            public function __construct(private ?float $ratio)
            {
                parent::__construct();
            }

            protected function currentUsageRatio(): ?float
            {
                return $this->ratio;
            }
        };
    }
}
