<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PluginConflict;
use App\Models\Site;
use App\Models\SitePluginConflict;
use App\Services\Notifications\NotificationService;

class PluginConflictService
{
    protected static array $duplicateFunctionality = [
        'Caching' => ['w3-total-cache', 'wp-super-cache', 'wp-fastest-cache', 'litespeed-cache', 'wp-rocket', 'breeze', 'sg-cachepress'],
        'Security' => ['wordfence', 'sucuri-scanner', 'better-wp-security', 'all-in-one-wp-security-and-firewall'],
        'Backup' => ['updraftplus', 'duplicator', 'backwpup', 'developer-developer'],
    ];

    public static function checkSite(Site $site): array
    {
        $activeSlugs = $site->sitePlugins()
            ->where('is_active', true)
            ->pluck('slug')
            ->toArray();

        $foundConflicts = [];

        // 1. Check against known conflicts in DB
        $knownConflicts = PluginConflict::all();

        foreach ($knownConflicts as $conflict) {
            if (in_array($conflict->plugin_a_slug, $activeSlugs) && in_array($conflict->plugin_b_slug, $activeSlugs)) {
                $foundConflicts[] = [
                    'plugin_a_slug' => $conflict->plugin_a_slug,
                    'plugin_b_slug' => $conflict->plugin_b_slug,
                    'plugin_conflict_id' => $conflict->id,
                ];
            }
        }

        // 2. Check for duplicate functionality categories
        foreach (static::$duplicateFunctionality as $category => $slugs) {
            $installedInCategory = array_intersect($activeSlugs, $slugs);

            if (count($installedInCategory) > 1) {
                $slugList = array_values($installedInCategory);
                for ($i = 0; $i < count($slugList); $i++) {
                    for ($j = $i + 1; $j < count($slugList); $j++) {
                        $a = $slugList[$i];
                        $b = $slugList[$j];

                        // Skip if already found from known conflicts
                        $alreadyFound = false;
                        foreach ($foundConflicts as $fc) {
                            if (($fc['plugin_a_slug'] === $a && $fc['plugin_b_slug'] === $b) ||
                                ($fc['plugin_a_slug'] === $b && $fc['plugin_b_slug'] === $a)) {
                                $alreadyFound = true;
                                break;
                            }
                        }

                        if (! $alreadyFound) {
                            // Check if there is a known conflict for this pair
                            $knownConflict = $knownConflicts->first(function ($c) use ($a, $b) {
                                return ($c->plugin_a_slug === $a && $c->plugin_b_slug === $b)
                                    || ($c->plugin_a_slug === $b && $c->plugin_b_slug === $a);
                            });

                            $foundConflicts[] = [
                                'plugin_a_slug' => $a,
                                'plugin_b_slug' => $b,
                                'plugin_conflict_id' => $knownConflict?->id,
                            ];
                        }
                    }
                }
            }
        }

        // Persist found conflicts
        $currentPairs = [];
        foreach ($foundConflicts as $fc) {
            $siteConflict = SitePluginConflict::firstOrCreate(
                [
                    'site_id' => $site->id,
                    'plugin_a_slug' => $fc['plugin_a_slug'],
                    'plugin_b_slug' => $fc['plugin_b_slug'],
                ],
                [
                    'plugin_conflict_id' => $fc['plugin_conflict_id'],
                    'status' => 'active',
                    'detected_at' => now(),
                ]
            );

            // Update conflict reference if it was missing
            if ($siteConflict->plugin_conflict_id !== $fc['plugin_conflict_id'] && $fc['plugin_conflict_id']) {
                $siteConflict->update(['plugin_conflict_id' => $fc['plugin_conflict_id']]);
            }

            $currentPairs[] = $siteConflict->id;
        }

        // Remove stale conflicts (no longer applicable)
        $site->sitePluginConflicts()
            ->where('status', 'active')
            ->whereNotIn('id', $currentPairs)
            ->delete();

        // Notify on critical conflicts
        $criticalConflicts = collect($foundConflicts)->filter(function ($fc) use ($knownConflicts) {
            if (! $fc['plugin_conflict_id']) {
                return false;
            }
            $conflict = $knownConflicts->firstWhere('id', $fc['plugin_conflict_id']);

            return $conflict && in_array($conflict->severity, ['critical', 'high']);
        });

        if ($criticalConflicts->isNotEmpty()) {
            NotificationService::notifySiteEvent(
                site: $site,
                event: 'plugin_conflict_detected',
                title: "Plugin conflicts detected on {$site->name}",
                message: $criticalConflicts->count().' critical/high severity conflict(s) found.',
                fields: [
                    'Total Conflicts' => (string) count($foundConflicts),
                    'Critical/High' => (string) $criticalConflicts->count(),
                ],
                severity: 'warning',
            );
        }

        return [
            'total' => count($foundConflicts),
            'critical' => $criticalConflicts->count(),
        ];
    }

    public static function dismiss(SitePluginConflict $conflict): void
    {
        $conflict->update(['status' => 'dismissed']);
    }
}
