<?php

declare(strict_types=1);

namespace App\Services\Backup;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DiskSpaceGuard
{
    private const ALERT_COOLDOWN_KEY = 'backup:disk_alert_sent_at';

    private const ALERT_COOLDOWN_SECONDS = 3600;

    public function __construct(
        // 10 GB minimum: v3-zip pipeline peaks at ~1.1x backup size on disk during
        // build (chunks shrinking as output zip grows). 10 GB headroom covers
        // sites up to ~9 GB with margin. Lower if you're running on smaller boxes.
        private readonly int $minFreeBytes = 10 * 1024 * 1024 * 1024,
        private readonly string $checkPath = ''
    ) {}

    public function canDispatchBackup(): bool
    {
        $path = $this->checkPath !== '' ? $this->checkPath : storage_path('app/temp');
        $free = @disk_free_space($path);

        if ($free === false) {
            return true; // fail open — don't block backups if we can't measure
        }

        if ($free >= $this->minFreeBytes) {
            return true;
        }

        $this->alertOnce($path, (int) $free);

        return false;
    }

    private function alertOnce(string $path, int $freeBytes): void
    {
        $freeGb = round($freeBytes / 1024 / 1024 / 1024, 2);
        $minGb = round($this->minFreeBytes / 1024 / 1024 / 1024, 2);

        if (Cache::has(self::ALERT_COOLDOWN_KEY)) {
            return;
        }

        Log::warning("DiskSpaceGuard: blocking backup dispatch — only {$freeGb} GB free at {$path} (threshold {$minGb} GB)", [
            'path' => $path,
            'free_bytes' => $freeBytes,
            'threshold_bytes' => $this->minFreeBytes,
        ]);

        Cache::put(self::ALERT_COOLDOWN_KEY, now()->toIso8601String(), self::ALERT_COOLDOWN_SECONDS);
    }
}
