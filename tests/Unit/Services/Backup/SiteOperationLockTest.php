<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Backup;

use App\Services\Backup\SiteOperationLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SiteOperationLockTest extends TestCase
{
    use RefreshDatabase; // locks live on the database store (audit E-06)

    /** E-06: flushing/evicting the default cache store must not drop the lock. */
    public function test_lock_survives_a_default_cache_store_flush(): void
    {
        $token = SiteOperationLock::acquire(1, SiteOperationLock::OPERATION_RESTORE);
        $this->assertNotNull($token);

        Cache::flush();

        $this->assertTrue(SiteOperationLock::isHeld(1));
        $this->assertTrue(SiteOperationLock::isOwnedBy(1, $token));
    }

    public function test_acquire_returns_token_and_blocks_second_acquire(): void
    {
        $token = SiteOperationLock::acquire(1, SiteOperationLock::OPERATION_RESTORE, 'backup:5');

        $this->assertNotNull($token);
        $this->assertNull(SiteOperationLock::acquire(1, SiteOperationLock::OPERATION_BACKUP));
        $this->assertTrue(SiteOperationLock::isHeld(1));
    }

    public function test_different_sites_do_not_contend(): void
    {
        $this->assertNotNull(SiteOperationLock::acquire(1, SiteOperationLock::OPERATION_RESTORE));
        $this->assertNotNull(SiteOperationLock::acquire(2, SiteOperationLock::OPERATION_RESTORE));
    }

    public function test_release_requires_matching_token(): void
    {
        $token = SiteOperationLock::acquire(1, SiteOperationLock::OPERATION_BACKUP);

        SiteOperationLock::release(1, 'wrong-token');
        $this->assertTrue(SiteOperationLock::isHeld(1));

        SiteOperationLock::release(1, $token);
        $this->assertFalse(SiteOperationLock::isHeld(1));
        $this->assertNotNull(SiteOperationLock::acquire(1, SiteOperationLock::OPERATION_RESTORE));
    }

    public function test_release_with_null_token_is_noop(): void
    {
        SiteOperationLock::acquire(1, SiteOperationLock::OPERATION_BACKUP);
        SiteOperationLock::release(1, null);

        $this->assertTrue(SiteOperationLock::isHeld(1));
    }

    public function test_reentrancy_via_is_owned_by(): void
    {
        $token = SiteOperationLock::acquire(1, SiteOperationLock::OPERATION_SAFE_UPDATE);

        $this->assertTrue(SiteOperationLock::isOwnedBy(1, $token));
        $this->assertFalse(SiteOperationLock::isOwnedBy(1, 'other'));
        $this->assertFalse(SiteOperationLock::isOwnedBy(1, null));
    }

    public function test_force_release_clears_any_owner(): void
    {
        SiteOperationLock::acquire(1, SiteOperationLock::OPERATION_RESTORE);
        SiteOperationLock::forceRelease(1);

        $this->assertFalse(SiteOperationLock::isHeld(1));
        $this->assertNull(SiteOperationLock::current(1));
    }

    public function test_current_exposes_holder_metadata(): void
    {
        SiteOperationLock::acquire(7, SiteOperationLock::OPERATION_RESTORE, 'backup:42');

        $current = SiteOperationLock::current(7);

        $this->assertSame(SiteOperationLock::OPERATION_RESTORE, $current['operation']);
        $this->assertSame('backup:42', $current['ref']);
        $this->assertArrayHasKey('acquired_at', $current);

        $this->assertNull(SiteOperationLock::current(8));
    }
}
