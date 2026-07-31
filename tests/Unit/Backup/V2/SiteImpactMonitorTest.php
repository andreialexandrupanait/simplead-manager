<?php

declare(strict_types=1);

namespace Tests\Unit\Backup\V2;

use App\Backup\V2\Exceptions\SiteUnderStrain;
use App\Backup\V2\Orchestration\SiteImpactMonitor;
use PHPUnit\Framework\TestCase;

/**
 * Know when to stop.
 *
 * Preflight answers "can this host start"; nothing answered "is this host still
 * coping". A backup is minutes of sustained work against a live site shared with
 * real visitors, and the point where it begins to hurt is not a number anyone
 * can configure in advance — a VPS answering in 200 ms and a shared host
 * answering in three seconds are both healthy, and both stop being healthy at
 * very different numbers.
 *
 * So the baseline is measured, and the signal is a sustained multiple of it
 * rather than any single slow answer.
 */
class SiteImpactMonitorTest extends TestCase
{
    private function warmed(SiteImpactMonitor $m, float $normal = 2.0): SiteImpactMonitor
    {
        for ($i = 0; $i < 3; $i++) {
            $m->observe($normal);
        }

        return $m;
    }

    public function test_a_steady_host_is_never_interrupted(): void
    {
        $m = $this->warmed(new SiteImpactMonitor);

        for ($i = 0; $i < 50; $i++) {
            $m->observe(2.2);
        }

        $this->expectNotToPerformAssertions();
    }

    /**
     * One slow response is ordinary — a garbage collection, a neighbour on the
     * same box, a cold cache. Tripping on it would park backups constantly.
     */
    public function test_a_single_slow_response_is_not_strain(): void
    {
        $m = $this->warmed(new SiteImpactMonitor);

        $m->observe(30.0);
        $m->observe(2.1);

        $this->expectNotToPerformAssertions();
    }

    public function test_sustained_slowdown_parks_the_backup(): void
    {
        $m = $this->warmed(new SiteImpactMonitor);

        $this->expectException(SiteUnderStrain::class);
        $this->expectExceptionMessageMatches('/slowed to .* per chunk/');

        $m->observe(30.0);
        $m->observe(30.0);
        $m->observe(30.0);
    }

    /** A run of slow answers broken by a normal one is a host that recovered. */
    public function test_recovery_resets_the_count(): void
    {
        $m = $this->warmed(new SiteImpactMonitor);

        $m->observe(30.0);
        $m->observe(30.0);
        $m->observe(2.0);
        $m->observe(30.0);
        $m->observe(30.0);

        $this->expectNotToPerformAssertions();
    }

    /**
     * A host answering in milliseconds would exceed any multiple on scheduler
     * jitter alone, so the baseline has a floor. Without it, the fastest and
     * healthiest sites would be the ones constantly interrupted.
     */
    public function test_a_very_fast_host_is_not_tripped_by_jitter(): void
    {
        $m = $this->warmed(new SiteImpactMonitor, normal: 0.02);

        $m->observe(0.4);
        $m->observe(0.4);
        $m->observe(0.4);

        $this->expectNotToPerformAssertions();
    }

    public function test_repeated_failures_park_the_backup(): void
    {
        $m = $this->warmed(new SiteImpactMonitor);

        $this->expectException(SiteUnderStrain::class);
        $this->expectExceptionMessageMatches('/failed 3 requests running/');

        $m->observeFailure('HTTP 502');
        $m->observeFailure('HTTP 502');
        $m->observeFailure('HTTP 502');
    }

    public function test_a_single_failure_is_not_strain(): void
    {
        $m = $this->warmed(new SiteImpactMonitor);

        $m->observeFailure('HTTP 502');
        $m->observe(2.0);
        $m->observeFailure('HTTP 502');

        $this->expectNotToPerformAssertions();
    }

    /**
     * The warm-up is measured on a host already under backup load, so those
     * samples are the honest reference. Tripping during it would mean judging a
     * host against a baseline we had not established.
     */
    public function test_nothing_trips_before_the_baseline_exists(): void
    {
        $m = new SiteImpactMonitor;

        $m->observe(60.0);
        $m->observe(60.0);

        $this->expectNotToPerformAssertions();
    }
}
