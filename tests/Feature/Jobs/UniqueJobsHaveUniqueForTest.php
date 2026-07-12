<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use ReflectionClass;
use Tests\TestCase;

/**
 * P1-07: a ShouldBeUnique job without a uniqueFor keeps its unique lock forever.
 * A worker killed with SIGKILL never releases the lock (Redis volatile-lru never
 * evicts a no-TTL key), so that job class is silently disabled fleet-wide until
 * Redis is flushed. Every unique job must therefore declare a positive uniqueFor
 * that comfortably exceeds its own timeout.
 */
class UniqueJobsHaveUniqueForTest extends TestCase
{
    public function test_every_unique_job_declares_a_positive_unique_for(): void
    {
        $offenders = [];

        foreach ($this->uniqueJobClasses() as $class) {
            $defaults = (new ReflectionClass($class))->getDefaultProperties();
            $uniqueFor = $defaults['uniqueFor'] ?? null;

            if (! is_int($uniqueFor) || $uniqueFor <= 0) {
                $offenders[] = $class;
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'ShouldBeUnique jobs missing a positive uniqueFor: '.implode(', ', $offenders),
        );
    }

    public function test_unique_for_exceeds_the_job_timeout(): void
    {
        $offenders = [];

        foreach ($this->uniqueJobClasses() as $class) {
            $defaults = (new ReflectionClass($class))->getDefaultProperties();
            $uniqueFor = $defaults['uniqueFor'] ?? null;
            $timeout = $defaults['timeout'] ?? null;

            if (is_int($uniqueFor) && is_int($timeout) && $timeout > 0 && $uniqueFor < $timeout) {
                $offenders[] = "{$class} (uniqueFor={$uniqueFor} < timeout={$timeout})";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'ShouldBeUnique jobs whose uniqueFor is shorter than their timeout: '.implode(', ', $offenders),
        );
    }

    /**
     * @return list<class-string>
     */
    private function uniqueJobClasses(): array
    {
        $classes = [];

        foreach (glob(app_path('Jobs/*.php')) ?: [] as $file) {
            $class = 'App\\Jobs\\'.pathinfo($file, PATHINFO_FILENAME);

            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if ($reflection->isAbstract() || ! $reflection->implementsInterface(ShouldBeUnique::class)) {
                continue;
            }

            $classes[] = $class;
        }

        // Sanity: the sweep must actually find the fleet of unique jobs.
        $this->assertGreaterThan(20, count($classes), 'Expected to discover the ShouldBeUnique job fleet.');

        return $classes;
    }
}
