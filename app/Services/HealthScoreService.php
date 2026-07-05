<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;

class HealthScoreService
{
    public static function calculate(Site $site): array
    {
        $site->loadMissing(['uptimeMonitor', 'performanceMonitor']);

        // Uptime component (25 points max)
        $uptimeScore = 0;
        if ($site->uptimeMonitor) {
            $pct = $site->uptimeMonitor->uptime_30d ?? 100;
            $uptimeScore = (int) round(min(25, ($pct / 100) * 25));
        } else {
            $uptimeScore = 25; // no monitor = assume ok
        }

        // Security component (25 points max)
        // The hardening score lives on sites.security_hardening_score;
        // security_monitors has no such column (D-P1-2).
        $securityScore = 0;
        $hardeningScore = $site->security_hardening_score;
        if ($hardeningScore !== null) {
            $securityScore = (int) round(($hardeningScore / 100) * 25);
        } else {
            $securityScore = 12; // unknown
        }

        // Updates component (25 points max)
        $pendingUpdates = $site->pending_updates_count ?? 0;
        if ($pendingUpdates === 0) {
            $updatesScore = 25;
        } elseif ($pendingUpdates <= 3) {
            $updatesScore = 20;
        } elseif ($pendingUpdates <= 10) {
            $updatesScore = 12;
        } else {
            $updatesScore = 5;
        }

        // Performance component (25 points max)
        $perfScore = 0;
        $latestPerf = $site->performanceMonitor?->latest_mobile_score ?? null;
        if ($latestPerf !== null) {
            $perfScore = (int) round(($latestPerf / 100) * 25);
        } else {
            $perfScore = 12;
        }

        $total = $uptimeScore + $securityScore + $updatesScore + $perfScore;

        return [
            'total' => $total,
            'components' => [
                'uptime' => ['score' => $uptimeScore, 'max' => 25, 'label' => 'Uptime'],
                'security' => ['score' => $securityScore, 'max' => 25, 'label' => 'Security'],
                'updates' => ['score' => $updatesScore, 'max' => 25, 'label' => 'Updates'],
                'performance' => ['score' => $perfScore, 'max' => 25, 'label' => 'Performance'],
            ],
        ];
    }
}
