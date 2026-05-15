<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Site;

/**
 * Computes a single 0–100 health score for a site's backup state.
 *
 * The score collapses several signals (recency, verification, replication,
 * last-attempt outcome) into one number that's easy to surface in the dashboard
 * and to filter by. The breakdown is exposed alongside the number so the UI can
 * explain WHY a site lost points.
 *
 * Sites without a BackupConfig and without any backup history are treated as
 * "not configured" (score = null) — distinct from "configured but failing"
 * (score = 0..20).
 */
class BackupHealthService
{
    public const STATUS_EXCELLENT = 'excellent';   // 80-100

    public const STATUS_OK = 'ok';                 // 50-79

    public const STATUS_WARNING = 'warning';       // 25-49

    public const STATUS_CRITICAL = 'critical';     // 0-24

    public const STATUS_UNCONFIGURED = 'unconfigured'; // no config

    /**
     * @return array{
     *   score: int|null,
     *   status: string,
     *   reasons: array<int, string>,
     *   last_backup_at: \Illuminate\Support\Carbon|null,
     *   last_status: string|null,
     *   replica_count: int,
     * }
     */
    public function scoreForSite(Site $site): array
    {
        $config = $site->backupConfig;
        $hasEverHadBackup = Backup::where('site_id', $site->id)->exists();

        if (! $config && ! $hasEverHadBackup) {
            return $this->unconfigured();
        }

        $latestCompleted = Backup::where('site_id', $site->id)
            ->where('status', 'completed')
            ->orderByDesc('completed_at')
            ->first();

        $latestAttempt = Backup::where('site_id', $site->id)
            ->orderByDesc('created_at')
            ->first();

        return $this->buildReport($config, $latestCompleted, $latestAttempt);
    }

    /**
     * Compute only the score integer from pre-fetched data (avoids N+1 in batch contexts).
     */
    public function computeScoreFromData(Site $site, ?\App\Models\BackupConfig $config, ?Backup $latestCompleted, ?Backup $latestAttempt): ?int
    {
        return $this->buildReport($config, $latestCompleted, $latestAttempt)['score'];
    }

    private function buildReport(?\App\Models\BackupConfig $config, ?Backup $latestCompleted, ?Backup $latestAttempt): array
    {
        $reasons = [];
        $score = 0;

        // Recency component (0-100 base score)
        if (! $latestCompleted) {
            $reasons[] = 'no successful backup ever';
            $base = 0;
        } else {
            $hoursAgo = $latestCompleted->completed_at?->diffInHours(now()) ?? 99999;
            $base = match (true) {
                $hoursAgo <= 24 => 100,
                $hoursAgo <= 36 => 60,
                $hoursAgo <= 72 => 40,
                $hoursAgo <= 168 => 20,   // 7 days
                default => 10,
            };
            if ($base < 100) {
                $reasons[] = sprintf('last completed backup %dh ago', (int) $hoursAgo);
            }
        }
        $score = $base;

        // Verification bonus/malus
        if ($latestCompleted) {
            $verifiedRecently = $latestCompleted->verified_at
                && $latestCompleted->verified_at->isAfter(now()->subDays(14));

            if ($base === 100 && ! $verifiedRecently) {
                $score = 80; // demote 100 → 80 if not verified
                $reasons[] = 'not verified in the last 14 days';
            }
            if ($latestCompleted->verification_status === 'failed') {
                $score = (int) max(0, $score - 30);
                $reasons[] = 'last verification check FAILED';
            }
        }

        // Last attempt failed: -20
        if ($latestAttempt && $latestAttempt->status->value === 'failed') {
            $score = (int) max(0, $score - 20);
            $reasons[] = 'most recent backup attempt failed';
        }

        // Single-replica penalty (only if site has any backup at all and no secondary configured)
        if ($latestCompleted) {
            $replicaCount = is_array($latestCompleted->replicas) ? count($latestCompleted->replicas) : 0;
            $secondaryConfigured = (bool) $config?->secondary_storage_destination_id;
            if ($replicaCount < 2 && ! $secondaryConfigured) {
                $score = (int) max(0, $score - 10);
                $reasons[] = 'single destination only (no off-site replica)';
            }
        }

        $score = (int) max(0, min(100, $score));

        return [
            'score' => $score,
            'status' => $this->statusFor($score),
            'reasons' => $reasons,
            'last_backup_at' => $latestCompleted?->completed_at,
            'last_status' => $latestAttempt?->status->value,
            'replica_count' => $latestCompleted
                ? (is_array($latestCompleted->replicas) ? count($latestCompleted->replicas) : 0)
                : 0,
        ];
    }

    /**
     * Aggregate health across all sites that have a backup config.
     *
     * @return array{
     *   avg_score: float|null,
     *   sites_count: int,
     *   excellent: int,
     *   ok: int,
     *   warning: int,
     *   critical: int,
     *   bottom: array<int, array{site_id: int, name: string, score: int, reasons: array<int, string>}>,
     * }
     */
    public function aggregate(int $bottomLimit = 5): array
    {
        $sites = Site::with('backupConfig')->get();

        $scores = [];
        $buckets = [
            self::STATUS_EXCELLENT => 0,
            self::STATUS_OK => 0,
            self::STATUS_WARNING => 0,
            self::STATUS_CRITICAL => 0,
        ];
        $perSite = [];

        foreach ($sites as $site) {
            $report = $this->scoreForSite($site);
            if ($report['score'] === null) {
                continue;
            }
            $scores[] = $report['score'];
            $buckets[$report['status']]++;
            $perSite[] = [
                'site_id' => $site->id,
                'name' => $site->name,
                'score' => $report['score'],
                'reasons' => $report['reasons'],
            ];
        }

        usort($perSite, fn ($a, $b) => $a['score'] <=> $b['score']);

        return [
            'avg_score' => $scores ? round(array_sum($scores) / count($scores), 1) : null,
            'sites_count' => count($scores),
            'excellent' => $buckets[self::STATUS_EXCELLENT],
            'ok' => $buckets[self::STATUS_OK],
            'warning' => $buckets[self::STATUS_WARNING],
            'critical' => $buckets[self::STATUS_CRITICAL],
            'bottom' => array_slice($perSite, 0, $bottomLimit),
        ];
    }

    private function statusFor(int $score): string
    {
        return match (true) {
            $score >= 80 => self::STATUS_EXCELLENT,
            $score >= 50 => self::STATUS_OK,
            $score >= 25 => self::STATUS_WARNING,
            default => self::STATUS_CRITICAL,
        };
    }

    /**
     * @return array{score: null, status: string, reasons: array<int, string>, last_backup_at: null, last_status: null, replica_count: int}
     */
    private function unconfigured(): array
    {
        return [
            'score' => null,
            'status' => self::STATUS_UNCONFIGURED,
            'reasons' => ['no backup config and no backup history'],
            'last_backup_at' => null,
            'last_status' => null,
            'replica_count' => 0,
        ];
    }
}
