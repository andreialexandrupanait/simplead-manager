<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\PluginConflictService;
use App\Services\WordPressApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncWordPressSite implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 30;

    public function __construct(
        public Site $site,
    ) {}

    public function handle(): void
    {
        $api = new WordPressApiService($this->site);

        try {
            // Sync site info
            $info = $api->getInfo();

            $this->site->update([
                'wp_version' => $info['wp_version'] ?? $this->site->wp_version,
                'php_version' => $info['php_version'] ?? $this->site->php_version,
                'server_software' => $info['server_software'] ?? $this->site->server_software,
                'is_multisite' => $info['is_multisite'] ?? $this->site->is_multisite,
                'db_size_mb' => $info['db_size_mb'] ?? $this->site->db_size_mb,
                'uploads_size_mb' => $info['uploads_size_mb'] ?? $this->site->uploads_size_mb,
                'core_update_version' => $info['core_update_version'] ?? null,
                'is_connected' => true,
                'last_synced_at' => now(),
            ]);

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
                        'is_active' => $plugin['is_active'] ?? false,
                        'has_update' => $plugin['has_update'] ?? false,
                        'update_version' => $plugin['update_version'] ?? null,
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
                        'is_active' => $theme['is_active'] ?? false,
                        'is_child_theme' => $theme['is_child_theme'] ?? false,
                        'parent_theme' => $theme['parent_theme'] ?? null,
                        'has_update' => $theme['has_update'] ?? false,
                        'update_version' => $theme['update_version'] ?? null,
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

            // Update pending updates count
            $pendingCount = $this->site->sitePlugins()->where('has_update', true)->count()
                + $this->site->siteThemes()->where('has_update', true)->count()
                + ($this->site->core_update_version ? 1 : 0);

            $this->site->update([
                'pending_updates_count' => $pendingCount,
            ]);

            // Auto-check plugin conflicts after sync
            try {
                PluginConflictService::checkSite($this->site);
            } catch (\Exception $e) {
                Log::info("Plugin conflict check skipped for site {$this->site->id}: {$e->getMessage()}");
            }

        } catch (\Exception $e) {
            Log::warning("WordPress sync failed for site {$this->site->id}: {$e->getMessage()}");

            $this->site->update([
                'is_connected' => false,
            ]);

            throw $e;
        }
    }
}
