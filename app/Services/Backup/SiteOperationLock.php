<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Cross-class mutual exclusion for destructive per-site operations
 * (backup / incremental backup / restore / safe update).
 *
 * ShouldBeUnique on the individual jobs only deduplicates the same job class;
 * this lock serializes ACROSS classes so a restore can never run concurrently
 * with a scheduled backup or a safe update on the same site.
 *
 * Re-entrancy: jobs that run nested operations synchronously (e.g. RunSafeUpdate
 * dispatching CreateBackup via dispatchSync) pass their token down; the callee
 * sees the lock is owned by its caller and proceeds without re-acquiring.
 *
 * Storage: locks live on a non-evictable store (database by default, see
 * cache.site_operation_lock_store). The shared Redis runs volatile-lru, where
 * a TTL'd lock key is an eviction candidate under memory pressure — an evicted
 * lock would let a restore run concurrently with a backup (audit E-06).
 */
class SiteOperationLock
{
    public const TTL_SECONDS = 7200;

    public const OPERATION_BACKUP = 'backup';

    public const OPERATION_INCREMENTAL_BACKUP = 'incremental_backup';

    public const OPERATION_RESTORE = 'restore';

    public const OPERATION_SAFE_UPDATE = 'safe_update';

    /**
     * Try to acquire the site lock. Returns an owner token on success, null when
     * another operation holds it.
     */
    public static function acquire(int $siteId, string $operation, string $ref = ''): ?string
    {
        $token = $operation.':'.Str::random(24);

        if (! self::store()->add(self::key($siteId), $token, self::TTL_SECONDS)) {
            return null;
        }

        self::store()->put(self::metaKey($siteId), [
            'operation' => $operation,
            'ref' => $ref,
            'acquired_at' => now()->toIso8601String(),
        ], self::TTL_SECONDS);

        return $token;
    }

    /** Release only if still owned by the given token (avoid releasing a successor's lock). */
    public static function release(int $siteId, ?string $token): void
    {
        if ($token === null) {
            return;
        }

        if (self::store()->get(self::key($siteId)) === $token) {
            self::store()->forget(self::key($siteId));
            self::store()->forget(self::metaKey($siteId));
        }
    }

    /** Unconditional release — operator tooling (backup:release-lock) only. */
    public static function forceRelease(int $siteId): void
    {
        self::store()->forget(self::key($siteId));
        self::store()->forget(self::metaKey($siteId));
    }

    public static function isOwnedBy(int $siteId, ?string $token): bool
    {
        return $token !== null && self::store()->get(self::key($siteId)) === $token;
    }

    public static function isHeld(int $siteId): bool
    {
        return self::store()->get(self::key($siteId)) !== null;
    }

    /** @return array{operation: string, ref: string, acquired_at: string}|null */
    public static function current(int $siteId): ?array
    {
        if (! self::isHeld($siteId)) {
            return null;
        }

        return self::store()->get(self::metaKey($siteId));
    }

    private static function store(): Repository
    {
        return Cache::store(config('cache.site_operation_lock_store', 'database'));
    }

    private static function key(int $siteId): string
    {
        return "site-op:{$siteId}";
    }

    private static function metaKey(int $siteId): string
    {
        return "site-op-meta:{$siteId}";
    }
}
