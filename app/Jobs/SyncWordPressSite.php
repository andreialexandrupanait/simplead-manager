<?php

namespace App\Jobs;

use App\Jobs\FetchSiteFavicon;
use App\Models\Site;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
use App\Services\PluginConflictService;
use App\Services\WordPressApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SyncWordPressSite implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public array $backoff = [30, 60, 120];

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'sync-wp-' . $this->site->id;
    }

    public function handle(): void
    {
        JobTracker::start($this->uniqueId(), 'Syncing site data...');

        $api = new WordPressApiService($this->site);

        try {
            // Sync site info
            $info = $api->getInfo();

            // Detect WooCommerce from active plugins
            $activePlugins = $info['active_plugins'] ?? [];
            $hasWooCommerce = in_array('woocommerce/woocommerce.php', $activePlugins);

            $this->site->update([
                'wp_version' => $info['wp_version'] ?? $this->site->wp_version,
                'php_version' => $info['php_version'] ?? $this->site->php_version,
                'server_software' => $info['server_software'] ?? $this->site->server_software,
                'is_multisite' => $info['is_multisite'] ?? $this->site->is_multisite,
                'db_size_mb' => $info['db_size_mb'] ?? $this->site->db_size_mb,
                'uploads_size_mb' => $info['uploads_size_mb'] ?? $this->site->uploads_size_mb,
                'core_update_version' => $info['core_new_version'] ?? null,
                'has_woocommerce' => $hasWooCommerce,
                'is_connected' => true,
                'last_synced_at' => now(),
            ]);

            JobTracker::progress($this->uniqueId(), 15, 'Syncing plugins...');

            // Sync plugins
            $pluginsData = $api->getPlugins();
            $existingPluginFiles = [];

            foreach ($pluginsData['plugins'] ?? [] as $plugin) {
                $this->site->sitePlugins()->updateOrCreate(
                    ['file' => $plugin['file']],
                    [
                        'slug' => $plugin['slug'] ?? '',
                        'name' => $plugin['name'] ?? '',
                        'version' => $plugin['version'] ?? null,
                        'author' => $plugin['author'] ?? null,
                        'author_uri' => $plugin['author_uri'] ?? null,
                        'plugin_uri' => $plugin['plugin_uri'] ?? null,
                        'description' => $plugin['description'] ?? null,
                        'is_active' => ($plugin['status'] ?? $plugin['is_active'] ?? '') === 'active',
                        'has_update' => $plugin['update_available'] ?? $plugin['has_update'] ?? false,
                        'update_version' => $plugin['new_version'] ?? $plugin['update_version'] ?? null,
                        'requires_wp' => $plugin['requires_wp'] ?? null,
                        'requires_php' => $plugin['requires_php'] ?? null,
                        'auto_update' => $plugin['auto_update'] ?? false,
                    ]
                );
                $existingPluginFiles[] = $plugin['file'];
            }

            // Remove plugins that no longer exist on the remote site
            $this->site->sitePlugins()
                ->whereNotIn('file', $existingPluginFiles)
                ->delete();

            JobTracker::progress($this->uniqueId(), 35, 'Syncing themes...');

            // Sync themes
            $themesData = $api->getThemes();
            $existingThemeSlugs = [];

            foreach ($themesData['themes'] ?? [] as $theme) {
                $this->site->siteThemes()->updateOrCreate(
                    ['slug' => $theme['slug']],
                    [
                        'name' => $theme['name'] ?? '',
                        'version' => $theme['version'] ?? null,
                        'author' => $theme['author'] ?? null,
                        'author_uri' => $theme['author_uri'] ?? null,
                        'description' => $theme['description'] ?? null,
                        'is_active' => ($theme['status'] ?? $theme['is_active'] ?? '') === 'active',
                        'is_child_theme' => !empty($theme['parent_theme']) || ($theme['is_child_theme'] ?? false),
                        'parent_theme' => $theme['parent_theme'] ?? null,
                        'has_update' => $theme['update_available'] ?? $theme['has_update'] ?? false,
                        'update_version' => $theme['new_version'] ?? $theme['update_version'] ?? null,
                        'screenshot_url' => $theme['screenshot_url'] ?? null,
                        'auto_update' => $theme['auto_update'] ?? false,
                    ]
                );
                $existingThemeSlugs[] = $theme['slug'];
            }

            // Remove themes that no longer exist on the remote site
            $this->site->siteThemes()
                ->whereNotIn('slug', $existingThemeSlugs)
                ->delete();

            JobTracker::progress($this->uniqueId(), 55, 'Syncing users...');

            // Sync users
            try {
                $usersData = $api->getUsers();
                $existingWpUserIds = [];

                foreach ($usersData['users'] ?? [] as $user) {
                    $this->site->siteUsers()->updateOrCreate(
                        ['wp_user_id' => $user['id']],
                        [
                            'username' => $user['username'] ?? '',
                            'email' => $user['email'] ?? null,
                            'display_name' => $user['display_name'] ?? null,
                            'role' => $user['role'] ?? null,
                            'avatar_url' => $user['avatar_url'] ?? null,
                            'posts_count' => $user['posts_count'] ?? 0,
                            'registered_at' => $user['registered'] ?? null,
                            'last_login_at' => $user['last_login'] ?? null,
                        ]
                    );
                    $existingWpUserIds[] = $user['id'];
                }

                // Remove users that no longer exist on the remote site
                $this->site->siteUsers()
                    ->whereNotIn('wp_user_id', $existingWpUserIds)
                    ->delete();
            } catch (\Exception $e) {
                // Users endpoint may not exist on older connector versions — skip silently
                Log::info("User sync skipped for site {$this->site->id}: {$e->getMessage()}");
            }

            JobTracker::progress($this->uniqueId(), 70, 'Updating metadata...');

            // Fetch favicon if not yet cached
            if (!$this->site->favicon_path) {
                FetchSiteFavicon::dispatch($this->site);
            }

            // Update pending updates count
            $pendingCount = $this->site->sitePlugins()->where('has_update', true)->count()
                + $this->site->siteThemes()->where('has_update', true)->count()
                + ($this->site->core_update_version ? 1 : 0);

            $this->site->update([
                'pending_updates_count' => $pendingCount,
            ]);

            JobTracker::progress($this->uniqueId(), 85, 'Checking plugin conflicts...');

            // Auto-check plugin conflicts after sync
            try {
                PluginConflictService::checkSite($this->site);
            } catch (\Exception $e) {
                Log::info("Plugin conflict check skipped for site {$this->site->id}: {$e->getMessage()}");
            }

            // Fetch DB cleanup stats for overview card
            try {
                $dbStats = $api->getDbCleanupStats();
                Cache::put("db-cleanup-stats-{$this->site->id}", $dbStats, now()->addHours(24));
            } catch (\Exception $e) {
                Log::info("DB cleanup stats skipped for site {$this->site->id}: {$e->getMessage()}");
            }

            JobTracker::progress($this->uniqueId(), 95, 'Finalizing...');

            CircuitBreakerService::recordSuccess($this->site);
            JobTracker::complete($this->uniqueId(), 'Site sync complete');

        } catch (\Exception $e) {
            Log::warning("WordPress sync failed for site {$this->site->id}: {$e->getMessage()}");

            $this->site->update([
                'is_connected' => false,
            ]);

            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        CircuitBreakerService::recordFailure($this->site, $exception?->getMessage() ?? 'WP sync failed');
        JobTracker::fail($this->uniqueId(), 'Sync failed: ' . ($exception?->getMessage() ?? 'Unknown error'));
    }
}
