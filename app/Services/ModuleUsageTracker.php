<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ModuleAccessLog;
use Illuminate\Support\Facades\Cache;

/**
 * Faza 7 — best-effort usage tracker for the modules under observation.
 *
 * Records one access row per module so the keep/remove decision at 60 days can
 * be made on real data. Instrumentation must NEVER break the page: every call
 * is wrapped in try/catch and fails silently.
 *
 * A short Cache guard debounces repeated hits from the same (user, module,
 * site) within a 60s window — this absorbs wire:poll / re-render churn so the
 * counts reflect distinct visits rather than render volume.
 */
class ModuleUsageTracker
{
    private const DEBOUNCE_TTL = 60;

    public function record(string $module, ?int $siteId = null, ?int $userId = null): void
    {
        try {
            if (! $this->passesDebounce($module, $siteId, $userId)) {
                return;
            }

            ModuleAccessLog::create([
                'module' => $module,
                'site_id' => $siteId,
                'user_id' => $userId,
                'accessed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            // Silent by design: usage instrumentation must not affect the request.
        }
    }

    /**
     * Returns true the first time a given key is seen within the window, false
     * on subsequent hits. If the cache store misbehaves we fall through to
     * logging (better a duplicate row than a lost signal).
     */
    private function passesDebounce(string $module, ?int $siteId, ?int $userId): bool
    {
        try {
            $key = 'module_usage:'.$module.':'.($siteId ?? 0).':'.($userId ?? 0);

            return Cache::add($key, 1, self::DEBOUNCE_TTL);
        } catch (\Throwable $e) {
            return true;
        }
    }
}
