<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Carbon\CarbonImmutable;

/**
 * SPEC §5.3 — "EOL WordPress și PHP". The WordPress half already exists
 * ({@see WordPressEolService}); this is the PHP half.
 *
 * PHP support runs on a published calendar rather than a "how many versions
 * behind" count, so the classification is date-driven: each branch gets two
 * years of active support and one further year of security fixes. A site is
 * judged against the SECURITY end date — after it, the branch receives no
 * patches at all, which is the operationally relevant line.
 *
 * The table is deliberately explicit and dateable rather than fetched: php.net
 * publishes no machine-readable feed we would want a nightly job to depend on,
 * the dates move rarely, and a stale table fails in the safe direction (an
 * unknown future branch reads as "unknown", never as "supported").
 *
 * Source: https://www.php.net/supported-versions.php
 */
class PhpEolService
{
    /**
     * branch => [active support ends, security support ends].
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const SUPPORT = [
        '7.0' => ['2018-12-03', '2019-01-10'],
        '7.1' => ['2018-12-01', '2019-12-01'],
        '7.2' => ['2019-11-30', '2020-11-30'],
        '7.3' => ['2020-12-06', '2021-12-06'],
        '7.4' => ['2021-11-28', '2022-11-28'],
        '8.0' => ['2022-11-26', '2023-11-26'],
        '8.1' => ['2023-11-25', '2025-12-31'],
        '8.2' => ['2024-12-31', '2026-12-31'],
        '8.3' => ['2025-12-31', '2027-12-31'],
        '8.4' => ['2026-12-31', '2028-12-31'],
        '8.5' => ['2027-12-31', '2029-12-31'],
    ];

    /** A branch whose security support ends within this window is "approaching". */
    private const APPROACHING_DAYS = 180;

    /**
     * Classify a site's PHP version and alert when it is unsupported.
     *
     * @return array{status: string, severity: ?string, message: string, branch: ?string, security_until: ?string}
     */
    public static function check(Site $site): array
    {
        $result = static::classify((string) $site->php_version);

        // Only an actually unsupported runtime is worth waking someone for; a
        // branch merely approaching its date is reported, not alerted.
        if ($result['status'] === 'eol') {
            self::notify($site, $result['message']);
        }

        return $result;
    }

    /**
     * Pure classification — no notifications, safe for UI and reports.
     *
     * @return array{status: string, severity: ?string, message: string, branch: ?string, security_until: ?string}
     */
    public static function classify(string $version): array
    {
        $branch = static::branch($version);

        if ($branch === null || ! isset(self::SUPPORT[$branch])) {
            return [
                'status' => 'unknown',
                'severity' => null,
                'message' => $version === ''
                    ? 'PHP version not detected.'
                    : "PHP {$version} is not in the known support table.",
                'branch' => $branch,
                'security_until' => null,
            ];
        }

        [$activeUntil, $securityUntil] = self::SUPPORT[$branch];
        $security = CarbonImmutable::parse($securityUntil)->endOfDay();
        $active = CarbonImmutable::parse($activeUntil)->endOfDay();
        $now = CarbonImmutable::now();

        if ($now->greaterThan($security)) {
            return [
                'status' => 'eol',
                'severity' => 'critical',
                'message' => "PHP {$branch} stopped receiving security fixes on {$security->format('d.m.Y')}.",
                'branch' => $branch,
                'security_until' => $securityUntil,
            ];
        }

        if ($now->greaterThan($security->subDays(self::APPROACHING_DAYS))) {
            return [
                'status' => 'approaching_eol',
                'severity' => 'warning',
                'message' => "PHP {$branch} loses security support on {$security->format('d.m.Y')}.",
                'branch' => $branch,
                'security_until' => $securityUntil,
            ];
        }

        if ($now->greaterThan($active)) {
            return [
                'status' => 'security_only',
                'severity' => null,
                'message' => "PHP {$branch} is in security-only support until {$security->format('d.m.Y')}.",
                'branch' => $branch,
                'security_until' => $securityUntil,
            ];
        }

        return [
            'status' => 'supported',
            'severity' => null,
            'message' => "PHP {$branch} is fully supported until {$active->format('d.m.Y')}.",
            'branch' => $branch,
            'security_until' => $securityUntil,
        ];
    }

    /**
     * The major.minor branch of a reported version. Hosts report odd shapes
     * ("8.1.34", "7.4.33.12", "8.3.32-1~deb12"), so only the first two numeric
     * segments are trusted.
     */
    public static function branch(string $version): ?string
    {
        if (! preg_match('/^(\d+)\.(\d+)/', trim($version), $m)) {
            return null;
        }

        return $m[1].'.'.$m[2];
    }

    private static function notify(Site $site, string $message): void
    {
        NotificationService::notifySiteEventSlim(
            site: $site,
            event: 'php_eol',
            summary: "*{$site->domain}* runs an unsupported PHP. {$message}",
            severity: 'warning',
        );
    }
}
