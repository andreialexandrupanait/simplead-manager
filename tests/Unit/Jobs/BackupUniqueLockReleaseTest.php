<?php

declare(strict_types=1);

namespace Tests\Unit\Jobs;

use App\Jobs\CreateBackup;
use App\Jobs\CreateIncrementalBackup;
use App\Jobs\RestoreBackup;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Tests\TestCase;

/**
 * P1-08: Laravel's ShouldBeUnique lock is ACQUIRED via
 * `$cache->lock($key)->get()` (Illuminate\Bus\UniqueLock), which — on the
 * redis cache store — targets the store's `lock_connection` (redis DB 0), not
 * its data `connection` (redis DB 1). The old release used `Cache::forget()`,
 * which hits the DATA connection, so it silently deleted nothing: a cancelled
 * or failed backup left the unique lock behind until its `uniqueFor` TTL
 * expired (up to 45 min), blocking every new backup for that site.
 *
 * The regression guard: releaseUniqueLock() must go through the same lock
 * primitive (`Cache::lock($key)->forceRelease()`), never `Cache::forget()`.
 * Because the whole Cache facade is mocked here, the OLD code's
 * `Cache::forget(...)` call has no expectation and blows up — so this test
 * fails on the buggy implementation and passes on the fix.
 */
class BackupUniqueLockReleaseTest extends TestCase
{
    private function expectLockReleaseFor(string $key): void
    {
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('forceRelease')->once();

        Cache::shouldReceive('lock')
            ->once()
            ->with($key)
            ->andReturn($lock);
    }

    public function test_create_backup_release_uses_lock_primitive(): void
    {
        $this->expectLockReleaseFor('laravel_unique_job:'.CreateBackup::class.':backup-42');

        CreateBackup::releaseUniqueLock(42);
    }

    public function test_incremental_backup_release_uses_lock_primitive(): void
    {
        $this->expectLockReleaseFor('laravel_unique_job:'.CreateIncrementalBackup::class.':backup-7');

        CreateIncrementalBackup::releaseUniqueLock(7);
    }

    public function test_restore_backup_release_uses_lock_primitive(): void
    {
        $this->expectLockReleaseFor('laravel_unique_job:'.RestoreBackup::class.':restore-99');

        RestoreBackup::releaseUniqueLock(99);
    }
}
