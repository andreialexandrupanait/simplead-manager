<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\DomainStatus;
use App\Models\SecurityRecommendation;
use App\Models\Site;
use App\Models\VulnerabilityAlert;

/**
 * Aggregates the actionable signals for a single site into one prioritised
 * to-do feed, so "what needs doing on this site" is answerable at a glance
 * instead of scattered across security, updates, backups and monitoring.
 *
 * Each item: category, priority, title, description, route (deep link), count.
 */
class SiteTodoService
{
    private const PRIORITY_WEIGHT = ['critical' => 0, 'high' => 1, 'medium' => 2, 'low' => 3];

    /**
     * @return list<array{category: string, priority: string, title: string, description: string, route: string, count: int}>
     */
    public static function forSite(Site $site): array
    {
        $items = [];

        if (! $site->is_up) {
            $items[] = self::item('uptime', 'critical', 'Site is down', 'The site is not responding to uptime checks.', 'sites.overview', $site);
        }

        $vulnCount = VulnerabilityAlert::where('site_id', $site->id)->active()->count();
        if ($vulnCount > 0) {
            $hasCriticalOrHigh = VulnerabilityAlert::where('site_id', $site->id)->active()
                ->whereIn('severity', ['critical', 'high'])->exists();
            $items[] = self::item(
                'security', $hasCriticalOrHigh ? 'critical' : 'medium',
                "{$vulnCount} active ".self::plural($vulnCount, 'vulnerability', 'vulnerabilities'),
                'Vulnerable plugins/themes detected. Update or remediate.',
                'sites.security.scanning', $site, $vulnCount,
            );
        }

        $failedHardening = SecurityRecommendation::where('site_id', $site->id)->where('status', 'failed')->count();
        if ($failedHardening > 0) {
            $items[] = self::item(
                'security', 'high',
                "{$failedHardening} hardening ".self::plural($failedHardening, 'check', 'checks').' failing',
                'Recommended security hardening is not applied.',
                'sites.security.hardening', $site, $failedHardening,
            );
        }

        $pendingUpdates = $site->sitePlugins()->where('has_update', true)->count()
            + $site->siteThemes()->where('has_update', true)->count();
        if ($pendingUpdates > 0) {
            $items[] = self::item(
                'updates', 'medium',
                "{$pendingUpdates} ".self::plural($pendingUpdates, 'update', 'updates').' available',
                'Plugins/themes have updates waiting.',
                'sites.plugins', $site, $pendingUpdates,
            );
        }

        $config = $site->backupConfig;
        $stale = ! $site->last_backup_at || $site->last_backup_at->lt(now()->subHours(36));
        if (! $config || ! $config->is_enabled) {
            $items[] = self::item('backups', 'high', 'Backups not configured', 'This site has no active backup schedule.', 'sites.backups', $site);
        } elseif ($stale) {
            $items[] = self::item('backups', 'high', 'Backup is stale', 'No successful backup in the last 36 hours.', 'sites.backups', $site);
        }

        // A backup that failed its restore test is worse than a stale one — it
        // exists but may not restore. Surface it prominently.
        $failedVerify = $site->backups()
            ->where('status', 'completed')
            ->where('verification_status', 'failed')
            ->exists();
        if ($failedVerify) {
            $items[] = self::item('backups', 'critical', 'Backup failed restore verification', 'A backup could not be verified as restorable — investigate before relying on it.', 'sites.backups', $site);
        }

        if ($site->domain_status === DomainStatus::Expired) {
            $items[] = self::item('domain', 'critical', 'Domain has expired', 'The domain registration has lapsed — renew immediately.', 'sites.overview', $site);
        } elseif ($site->domain_status === DomainStatus::ExpiringSoon) {
            $when = $site->domain_expires_at ? $site->domain_expires_at->diffForHumans() : 'soon';
            $items[] = self::item('domain', 'medium', 'Domain expiring soon', "The domain registration expires {$when}.", 'sites.overview', $site);
        }

        usort($items, fn ($a, $b) => self::PRIORITY_WEIGHT[$a['priority']] <=> self::PRIORITY_WEIGHT[$b['priority']]);

        return $items;
    }

    /**
     * @return array{category: string, priority: string, title: string, description: string, route: string, count: int}
     */
    private static function item(string $category, string $priority, string $title, string $description, string $routeName, Site $site, int $count = 1): array
    {
        return [
            'category' => $category,
            'priority' => $priority,
            'title' => $title,
            'description' => $description,
            'route' => route($routeName, $site),
            'count' => $count,
        ];
    }

    private static function plural(int $n, string $singular, string $plural): string
    {
        return $n === 1 ? $singular : $plural;
    }
}
