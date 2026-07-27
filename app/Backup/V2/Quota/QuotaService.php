<?php

declare(strict_types=1);

namespace App\Backup\V2\Quota;

use App\Backup\V2\Notifications\BackupV2Notifier;
use App\Helpers\FormatHelper;
use App\Models\StorageDestination;

/**
 * Storage quota policy for the V2 backup engine (P5).
 *
 * The atomic quantity is a StorageDestination's `used_bytes` — the RECONCILED
 * truth maintained by App\Backup\V2\Jobs\ReconcileUsedBytesJob (summed object
 * sizes from storage), NOT a running best-effort counter. A new backup that would
 * push `used_bytes` past `quota_bytes` is refused.
 *
 * SAFETY: enforcement is gated by config('backup_v2.quota.enforce'). With it off
 * the service still REPORTS usage / percentages (so the UI can render meters) but
 * assertWithinQuota() never blocks — a production build that has not opted into V2
 * is never affected. Reporting methods are always safe to call.
 */
final class QuotaService
{
    public function __construct(
        private readonly BackupV2Notifier $notifier = new BackupV2Notifier,
    ) {}

    /**
     * Reconciled bytes currently stored on the destination.
     */
    public function usedBytes(StorageDestination $destination): int
    {
        return max(0, (int) $destination->used_bytes);
    }

    /**
     * Quota ceiling in bytes, or null when the destination is unbounded.
     */
    public function quotaBytes(StorageDestination $destination): ?int
    {
        $quota = $destination->quota_bytes;

        return $quota === null || $quota <= 0 ? null : (int) $quota;
    }

    /**
     * Remaining head-room in bytes, or null when unbounded.
     */
    public function remainingBytes(StorageDestination $destination): ?int
    {
        $quota = $this->quotaBytes($destination);
        if ($quota === null) {
            return null;
        }

        return max(0, $quota - $this->usedBytes($destination));
    }

    /**
     * Usage as a percentage of quota (0–100+), or null when unbounded.
     */
    public function usagePercent(StorageDestination $destination): ?float
    {
        $quota = $this->quotaBytes($destination);
        if ($quota === null) {
            return null;
        }

        return round($this->usedBytes($destination) / $quota * 100, 1);
    }

    /**
     * Would adding $projectedBytes exceed the quota? Unbounded destinations never
     * exceed. $projectedBytes defaults to 0 (already-at-limit check).
     */
    public function wouldExceed(StorageDestination $destination, int $projectedBytes = 0): bool
    {
        $quota = $this->quotaBytes($destination);
        if ($quota === null) {
            return false;
        }

        return $this->usedBytes($destination) + max(0, $projectedBytes) > $quota;
    }

    /**
     * Is usage at/above the warning threshold (drives the storage-limit alert)?
     */
    public function isOverWarnThreshold(StorageDestination $destination): bool
    {
        $percent = $this->usagePercent($destination);
        if ($percent === null) {
            return false;
        }

        return $percent >= (float) config('backup_v2.quota.warn_threshold_percent', 90);
    }

    /**
     * Enforce the quota before a new backup. No-op (reporting only) unless
     * enforcement is enabled. Throws QuotaExceededException with a clear message
     * when a backup would breach the ceiling.
     */
    public function assertWithinQuota(StorageDestination $destination, int $projectedBytes = 0): void
    {
        if (! $this->enforcementEnabled()) {
            return;
        }

        if (! $this->wouldExceed($destination, $projectedBytes)) {
            return;
        }

        $quota = (int) $this->quotaBytes($destination);
        $used = $this->usedBytes($destination);
        $projected = $used + max(0, $projectedBytes);

        // A breach is also a "limit reached" event → fire the alert.
        $this->notifier->storageQuotaReached($destination);

        throw new QuotaExceededException(
            destination: $destination,
            usedBytes: $used,
            quotaBytes: $quota,
            projectedBytes: $projected,
            message: sprintf(
                'Storage quota reached on "%s": %s of %s used%s. Free space or raise the quota before backing up.',
                $destination->name,
                FormatHelper::bytes($used),
                FormatHelper::bytes($quota),
                $projectedBytes > 0 ? ' (this backup needs ~'.FormatHelper::bytes($projectedBytes).')' : '',
            ),
        );
    }

    /**
     * Report + alert if the destination has crossed the warning threshold. Used by
     * the reconcile path and the UI to raise the storage-limit notification once
     * usage climbs. Returns whether an alert was fired.
     */
    public function checkAndAlert(StorageDestination $destination): bool
    {
        if (! $this->isOverWarnThreshold($destination)) {
            return false;
        }

        return $this->notifier->storageQuotaReached($destination);
    }

    private function enforcementEnabled(): bool
    {
        return (bool) config('backup_v2.quota.enforce', false);
    }
}
