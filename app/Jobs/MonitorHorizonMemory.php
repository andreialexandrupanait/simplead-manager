<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * P1-06: the Horizon container's hard memory limit sits below the summed
 * per-worker restart thresholds, so a burst of concurrent large jobs can trip a
 * cgroup OOM SIGKILL mid-backup/restore. This job runs *inside* the Horizon
 * container (it is dispatched to a queue Horizon processes), reads the
 * container's own cgroup memory usage, and raises a critical alert before the
 * container reaches its limit so an operator can intervene or scale.
 */
class MonitorHorizonMemory implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 15;

    public int $tries = 1;

    private const CACHE_KEY = 'horizon_memory_pressure_notified';

    public function __construct(public float $threshold = 0.85)
    {
        // Small and fast; ride the notifications queue so it executes in the
        // Horizon container rather than the scheduler.
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $ratio = $this->currentUsageRatio();

        // Not running under a readable cgroup (dev, cgroup v1 unlimited, etc.) —
        // nothing to assert; stay silent.
        if ($ratio === null) {
            return;
        }

        if ($ratio < $this->threshold) {
            Cache::forget(self::CACHE_KEY);

            return;
        }

        if (Cache::has(self::CACHE_KEY)) {
            return;
        }

        NotificationService::notifyAppEvent(
            event: 'horizon_memory_pressure',
            title: 'Horizon Memory Near Container Limit',
            message: sprintf(
                'The Horizon container is using %.0f%% of its memory limit (threshold %.0f%%). Queue workers risk an OOM SIGKILL mid-backup/restore — raise the container memory limit or reduce worker counts.',
                $ratio * 100,
                $this->threshold * 100,
            ),
            severity: 'critical',
            // Send inline: a queued alert could itself be lost if the container
            // is about to be OOM-killed.
            sync: true,
        );

        Cache::put(self::CACHE_KEY, true, 1800);
    }

    /**
     * Fraction of the container memory limit currently in use, or null when the
     * cgroup files are unreadable / the limit is unbounded.
     */
    protected function currentUsageRatio(): ?float
    {
        [$current, $limit] = $this->readCgroupMemory();

        if ($current === null || $limit === null || $limit <= 0) {
            return null;
        }

        return $current / $limit;
    }

    /**
     * @return array{0: int|null, 1: int|null} [currentBytes, limitBytes]
     */
    private function readCgroupMemory(): array
    {
        // cgroup v2 (Docker default on modern hosts).
        $currentPath = '/sys/fs/cgroup/memory.current';
        $maxPath = '/sys/fs/cgroup/memory.max';
        if (is_readable($currentPath) && is_readable($maxPath)) {
            $current = (int) trim((string) @file_get_contents($currentPath));
            $rawMax = trim((string) @file_get_contents($maxPath));
            $limit = $rawMax === 'max' ? null : (int) $rawMax;

            return [$current ?: null, $limit];
        }

        // cgroup v1 fallback.
        $currentV1 = '/sys/fs/cgroup/memory/memory.usage_in_bytes';
        $maxV1 = '/sys/fs/cgroup/memory/memory.limit_in_bytes';
        if (is_readable($currentV1) && is_readable($maxV1)) {
            $current = (int) trim((string) @file_get_contents($currentV1));
            $limit = (int) trim((string) @file_get_contents($maxV1));

            // v1 reports a near-INT_MAX sentinel when memory is unlimited.
            if ($limit > (1 << 62)) {
                $limit = null;
            }

            return [$current ?: null, $limit];
        }

        return [null, null];
    }
}
