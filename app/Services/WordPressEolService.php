<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WordPressEolService
{
    /**
     * WordPress branches considered end-of-life (no longer receiving security patches).
     * Updated periodically — WordPress only actively patches the latest branch.
     */
    private const EOL_BEFORE = '6.0';

    private const CACHE_KEY = 'wp_latest_version';

    private const CACHE_TTL = 86400; // 24 hours

    /**
     * Check a site's WordPress version and alert if outdated or EOL.
     *
     * @return array{status: string, severity: ?string, message: string, behind: int}
     */
    public static function check(Site $site): array
    {
        $version = $site->wp_version;

        if (! $version) {
            return ['status' => 'unknown', 'severity' => null, 'message' => 'WordPress version not detected', 'behind' => 0];
        }

        $latestVersion = static::getLatestVersion();
        $majorBehind = static::majorVersionsBehind($version, $latestVersion);
        $isEol = version_compare(static::extractBranch($version), self::EOL_BEFORE, '<');

        if ($isEol) {
            static::notify($site, 'critical', $version, $latestVersion, $majorBehind);

            return [
                'status' => 'eol',
                'severity' => 'critical',
                'message' => "WordPress {$version} has reached end-of-life and no longer receives security patches.",
                'behind' => $majorBehind,
            ];
        }

        if ($majorBehind >= 3) {
            static::notify($site, 'high', $version, $latestVersion, $majorBehind);

            return [
                'status' => 'outdated',
                'severity' => 'high',
                'message' => "WordPress {$version} is {$majorBehind} major versions behind ({$latestVersion}).",
                'behind' => $majorBehind,
            ];
        }

        if ($majorBehind >= 2) {
            return [
                'status' => 'outdated',
                'severity' => 'warning',
                'message' => "WordPress {$version} is {$majorBehind} major versions behind ({$latestVersion}).",
                'behind' => $majorBehind,
            ];
        }

        return [
            'status' => 'current',
            'severity' => null,
            'message' => 'WordPress version is current.',
            'behind' => $majorBehind,
        ];
    }

    /**
     * Get the classification for a WP version (used by UI without triggering notifications).
     *
     * @return array{status: string, severity: ?string, label: string, behind: int}
     */
    public static function classify(string $version): array
    {
        $latestVersion = static::getLatestVersion();
        $majorBehind = static::majorVersionsBehind($version, $latestVersion);
        $isEol = version_compare(static::extractBranch($version), self::EOL_BEFORE, '<');

        if ($isEol) {
            return ['status' => 'eol', 'severity' => 'critical', 'label' => 'End of Life', 'behind' => $majorBehind];
        }

        if ($majorBehind >= 3) {
            return ['status' => 'outdated', 'severity' => 'high', 'label' => 'Severely outdated', 'behind' => $majorBehind];
        }

        if ($majorBehind >= 2) {
            return ['status' => 'outdated', 'severity' => 'warning', 'label' => "{$majorBehind} versions behind", 'behind' => $majorBehind];
        }

        if ($majorBehind === 1) {
            return ['status' => 'minor', 'severity' => null, 'label' => 'Update available', 'behind' => $majorBehind];
        }

        return ['status' => 'current', 'severity' => null, 'label' => 'Up to date', 'behind' => 0];
    }

    /**
     * Fetch the latest WordPress version from the API (cached 24h).
     */
    public static function getLatestVersion(): string
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, function () {
            try {
                $response = Http::timeout(10)->get('https://api.wordpress.org/core/version-check/1.7/');

                if ($response->successful()) {
                    $version = $response->json('offers.0.version');
                    if ($version) {
                        return $version;
                    }
                }
            } catch (\Exception $e) {
                Log::info("WordPress version API check failed: {$e->getMessage()}");
            }

            // Fallback: return a reasonable default
            return '6.7.2';
        });
    }

    /**
     * Calculate how many major versions behind a version is.
     */
    private static function majorVersionsBehind(string $current, string $latest): int
    {
        $currentParts = explode('.', $current);
        $latestParts = explode('.', $latest);

        $currentMajor = (int) ($currentParts[0] ?? 0);
        $currentMinor = (int) ($currentParts[1] ?? 0);
        $latestMajor = (int) ($latestParts[0] ?? 0);
        $latestMinor = (int) ($latestParts[1] ?? 0);

        // WordPress versioning: 6.5 → 6.6 → 6.7 → 7.0
        // Each minor bump is a "major release" in WP terms
        return ($latestMajor * 10 + $latestMinor) - ($currentMajor * 10 + $currentMinor);
    }

    /**
     * Extract the major.minor branch from a full version string.
     */
    private static function extractBranch(string $version): string
    {
        $parts = explode('.', $version);

        return ($parts[0] ?? '0').'.'.($parts[1] ?? '0');
    }

    /**
     * Send a notification for outdated/EOL WordPress version.
     */
    private static function notify(Site $site, string $severity, string $current, string $latest, int $behind): void
    {
        $label = $severity === 'critical' ? 'End-of-Life' : 'Severely Outdated';

        NotificationService::notifySiteEvent(
            site: $site,
            event: 'wordpress_version_eol',
            title: "WordPress {$label}: {$site->name}",
            message: "WordPress {$current} is {$behind} major versions behind the latest ({$latest}). Immediate update recommended.",
            fields: [
                'Current Version' => $current,
                'Latest Version' => $latest,
                'Versions Behind' => (string) $behind,
                'Status' => $label,
            ],
            severity: $severity,
        );
    }
}
