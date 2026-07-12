<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\DiskSpaceGuard;
use Tests\TestCase;

/**
 * P1-39: DiskSpaceGuard grows a reusable pre-flight check so restore and
 * replication can refuse work that clearly cannot fit, instead of filling the
 * disk mid-flight and halting fleet-wide backups.
 */
class DiskSpaceGuardTest extends TestCase
{
    public function test_has_space_for_small_requirement_is_true(): void
    {
        $guard = new DiskSpaceGuard(checkPath: sys_get_temp_dir());

        $this->assertTrue($guard->hasSpaceFor(1024));
    }

    public function test_has_space_for_impossible_requirement_is_false(): void
    {
        $guard = new DiskSpaceGuard(checkPath: sys_get_temp_dir());

        // No real volume has an exabyte free.
        $this->assertFalse($guard->hasSpaceFor(2_000_000_000_000_000_000));
    }

    public function test_free_bytes_reports_a_positive_number(): void
    {
        $guard = new DiskSpaceGuard(checkPath: sys_get_temp_dir());

        $this->assertIsInt($guard->freeBytes());
        $this->assertGreaterThan(0, $guard->freeBytes());
    }

    public function test_has_space_fails_open_when_path_unmeasurable(): void
    {
        $guard = new DiskSpaceGuard(checkPath: '/nonexistent-path-'.uniqid());

        // Cannot measure → never block on a bad reading.
        $this->assertTrue($guard->hasSpaceFor(2_000_000_000_000_000_000));
    }
}
