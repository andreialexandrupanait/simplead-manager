<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use App\Models\SitePlugin;
use App\Services\Notifications\NotificationService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PluginAbandonmentService
{
    public static function checkPlugin(SitePlugin $plugin): void
    {
        if (! $plugin->slug) {
            $plugin->update([
                'is_on_wp_org' => false,
                'abandoned_checked_at' => now(),
            ]);

            return;
        }

        try {
            $response = Http::timeout(15)->retry(3, 100, throw: false)->get('https://api.wordpress.org/plugins/info/1.2/', [
                'action' => 'plugin_information',
                'slug' => $plugin->slug,
            ]);

            if ($response->status() === 404) {
                $plugin->update([
                    'is_on_wp_org' => false,
                    'is_closed' => true,
                    'closed_reason' => 'not_found',
                    'abandoned_checked_at' => now(),
                ]);

                return;
            }

            if ($response->failed()) {
                Log::warning("WordPress.org API returned {$response->status()} for plugin {$plugin->slug}");
                $plugin->update(['abandoned_checked_at' => now()]);

                return;
            }

            $data = $response->json();

            // Check if plugin is closed/removed
            if (isset($data['closed']) && $data['closed']) {
                $plugin->update([
                    'is_on_wp_org' => true,
                    'is_closed' => true,
                    'closed_reason' => $data['closed_reason'] ?? null,
                    'wp_org_last_updated' => isset($data['last_updated']) ? Carbon::parse($data['last_updated']) : null,
                    'abandoned_checked_at' => now(),
                ]);

                return;
            }

            $lastUpdated = isset($data['last_updated']) ? Carbon::parse($data['last_updated']) : null;
            $abandonmentYears = config('monitoring.plugin_abandonment_years', 2);
            $isAbandoned = $lastUpdated && $lastUpdated->lt(now()->subYears($abandonmentYears));

            $plugin->update([
                'is_on_wp_org' => true,
                'is_closed' => false,
                'closed_reason' => null,
                'is_abandoned' => $isAbandoned,
                'wp_org_last_updated' => $lastUpdated,
                'abandoned_checked_at' => now(),
            ]);
        } catch (RequestException|\RuntimeException $e) {
            Log::info("Abandonment check failed for plugin {$plugin->slug}: {$e->getMessage()}");
            $plugin->update([
                'abandoned_checked_at' => now(),
            ]);
        }
    }

    public static function checkAllForSite(Site $site, ?string $trackerKey = null): array
    {
        $plugins = $site->sitePlugins()->get();
        $abandoned = 0;
        $closed = 0;
        $total = $plugins->count();
        $completed = 0;

        foreach ($plugins as $plugin) {
            /** @var \App\Models\SitePlugin $plugin */
            if ($trackerKey && $total > 0) {
                $pluginName = $plugin->name ?: $plugin->slug ?: 'plugin';
                JobTracker::progress($trackerKey, (int) round($completed / $total * 90), "Checking {$pluginName}...");
            }

            static::checkPlugin($plugin);

            $plugin->refresh();
            if ($plugin->is_abandoned) {
                $abandoned++;
            }
            if ($plugin->is_closed) {
                $closed++;
            }
            $completed++;

            // Rate limit: 1 second between requests
            if ($plugins->last() !== $plugin) {
                sleep(1);
            }
        }

        if ($trackerKey) {
            JobTracker::progress($trackerKey, 95, 'Finalizing...');
        }

        $total = $plugins->count();

        if ($abandoned > 0 || $closed > 0) {
            NotificationService::notifySiteEvent(
                site: $site,
                event: 'abandoned_plugins_found',
                title: "Plugin issues found on {$site->name}",
                message: "{$abandoned} abandoned and {$closed} closed plugin(s) detected.",
                fields: [
                    'Abandoned Plugins' => (string) $abandoned,
                    'Closed Plugins' => (string) $closed,
                    'Total Plugins' => (string) $total,
                ],
                severity: 'warning',
            );
        }

        ActivityLogger::log(
            type: 'security',
            severity: ($abandoned > 0 || $closed > 0) ? 'warning' : 'info',
            title: "Abandoned plugin check for {$site->name}",
            description: "{$abandoned} abandoned, {$closed} closed out of {$total} plugins.",
            site: $site,
            icon: 'puzzle',
            url: route('sites.plugins', $site),
        );

        return ['abandoned' => $abandoned, 'closed' => $closed, 'total' => $total];
    }
}
