<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\WordPressApiException;
use App\Models\Site;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
use App\Services\Notifications\NotificationService;
use App\Services\PluginConflictService;
use App\Services\SecurityRecommendationService;
use App\Services\WordPressApiServiceFactory;
use App\Services\WordPressEolService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SyncWordPressSite implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 360; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public array $backoff = [30, 60, 120];

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'sync-wp-'.$this->site->id;
    }

    public function handle(): void
    {
        JobTracker::start($this->uniqueId(), 'Syncing site data...');

        $api = app(WordPressApiServiceFactory::class)->make($this->site);

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
                'core_update_version' => $info['core_new_version'] ?? null,
                'connector_version' => $info['plugin_version'] ?? $this->site->connector_version,
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
                        'is_on_wp_org' => $plugin['is_on_wp_org'] ?? null,
                    ] + (! empty($plugin['license_key']) ? [
                        'license_key' => $plugin['license_key'],
                        'license_status' => $plugin['license_status'] ?? 'active',
                        'license_expires_at' => ! empty($plugin['license_expires_at']) ? $plugin['license_expires_at'] : null,
                    ] : [])
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
                        'is_child_theme' => ! empty($theme['parent_theme']) || ($theme['is_child_theme'] ?? false),
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
                    try {
                        $registered = $user['registered'] ?? null;
                        $lastLogin = $user['last_login'] ?? null;
                        // Sanitize invalid dates (e.g. "-0001-11-30") that PostgreSQL rejects
                        if ($registered && (str_starts_with($registered, '-') || str_starts_with($registered, '0000'))) {
                            $registered = null;
                        }
                        if ($lastLogin && (str_starts_with($lastLogin, '-') || str_starts_with($lastLogin, '0000'))) {
                            $lastLogin = null;
                        }

                        $this->site->siteUsers()->updateOrCreate(
                            ['wp_user_id' => $user['id']],
                            [
                                'username' => $user['login'] ?? $user['username'] ?? '',
                                'email' => $user['email'] ?? null,
                                'display_name' => $user['display_name'] ?? null,
                                'role' => $user['roles'][0] ?? $user['role'] ?? null,
                                'avatar_url' => $user['avatar_url'] ?? null,
                                'orders_count' => $user['orders_count'] ?? 0,
                                'posts_count' => $user['posts_count'] ?? 0,
                                'registered_at' => $registered,
                                'last_login_at' => $lastLogin,
                                'synced_at' => now(),
                            ]
                        );
                    } catch (\Exception $e) {
                        Log::info("User sync failed for wp_user_id {$user['id']} on site {$this->site->id}: {$e->getMessage()}");
                    }
                    $existingWpUserIds[] = $user['id'];
                }

                // Remove users that no longer exist on the remote site
                $this->site->siteUsers()
                    ->whereNotIn('wp_user_id', $existingWpUserIds)
                    ->delete();
            } catch (RequestException|\RuntimeException $e) {
                // Users endpoint may not exist on older connector versions â skip silently
                Log::info("User sync skipped for site {$this->site->id}: {$e->getMessage()}");
            }

            JobTracker::progress($this->uniqueId(), 70, 'Updating metadata...');

            // Fetch favicon if not yet cached
            if (! $this->site->favicon_path) {
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

            // Run security checks
            try {
                app(SecurityRecommendationService::class)->check($this->site);
            } catch (\Exception $e) {
                Log::info("Security check skipped for site {$this->site->id}: {$e->getMessage()}");
            }

            // Check WordPress version EOL status
            try {
                WordPressEolService::check($this->site);
            } catch (\Exception $e) {
                Log::info("WordPress EOL check skipped for site {$this->site->id}: {$e->getMessage()}");
            }

            // Pull security activity logs from WordPress
            try {
                PullSecurityActivityLogs::dispatch($this->site);
            } catch (\Exception $e) {
                Log::info("Security activity log pull skipped for site {$this->site->id}: {$e->getMessage()}");
            }

            // Fetch DB cleanup stats for overview card
            try {
                $dbStats = $api->getDbCleanupStats();
                Cache::put("db-cleanup-stats-{$this->site->id}", $dbStats, now()->addHours(24));
            } catch (RequestException|\RuntimeException $e) {
                Log::info("DB cleanup stats skipped for site {$this->site->id}: {$e->getMessage()}");
            }

            JobTracker::progress($this->uniqueId(), 95, 'Finalizing...');

            CircuitBreakerService::recordSuccess($this->site);
            JobTracker::complete($this->uniqueId(), 'Site sync complete');

        } catch (\Exception $e) {
            // Do NOT flip is_connected here. A transient blip (timeout, 5xx,
            // connection reset) must not permanently disable this site's
            // backups/scans/sync. Retries + backoff run first; the connection
            // flag is only touched in failed() and only on a genuine auth/4xx
            // failure once all tries are exhausted.
            Log::warning("WordPress sync failed for site {$this->site->id}: {$e->getMessage()}");

            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        CircuitBreakerService::recordFailure($this->site, $exception?->getMessage() ?? 'WP sync failed');
        JobTracker::fail($this->uniqueId(), 'Sync failed: '.($exception?->getMessage() ?? 'Unknown error'));

        // Only a genuine auth/permission (4xx) failure means the connector is
        // no longer usable. Transient failures (connection errors, timeouts,
        // 5xx, rate limits) leave is_connected untrue so the hourly reconnect
        // probe and normal pipelines keep running.
        if (! $this->isGenuineDisconnect($exception)) {
            return;
        }

        // Reload current state â avoid re-notifying a site that is already
        // flagged disconnected.
        $this->site->refresh();

        if (! $this->site->is_connected) {
            return;
        }

        $this->site->update(['is_connected' => false]);

        Log::warning(
            "Site {$this->site->id} ({$this->site->name}) marked disconnected after auth/4xx failure: "
            .($exception?->getMessage() ?? 'unknown')
        );

        NotificationService::notifySiteEvent(
            $this->site,
            'site_disconnected',
            'Site Disconnected',
            "SimpleAd can no longer reach {$this->site->name}. Backups, security scans, performance tests and sync are paused for this site until the connection is restored.",
            [
                'Site' => $this->site->name,
                'URL' => $this->site->url,
                'Reason' => Str::limit($exception?->getMessage() ?? 'Authentication failed', 200),
            ],
            'critical',
        );
    }

    /**
     * A genuine disconnect is a 4xx (auth/permission/not-found) response â the
     * connector rejected us and retrying will not help. Rate-limit (429) and
     * request-timeout (408) are transient and explicitly excluded. Anything
     * without an HTTP status (connection reset, DNS failure, read timeout, 5xx)
     * is transient by definition.
     */
    private function isGenuineDisconnect(?\Throwable $exception): bool
    {
        $status = $this->httpStatusOf($exception);

        if ($status === null) {
            return false;
        }

        return $status >= 400 && $status < 500 && ! in_array($status, [408, 429], true);
    }

    private function httpStatusOf(?\Throwable $exception): ?int
    {
        if ($exception instanceof WordPressApiException) {
            return $exception->httpStatus;
        }

        if ($exception instanceof RequestException) {
            return $exception->response->status();
        }

        return null;
    }
}
