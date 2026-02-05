<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class WordPressApiService
{
    public function __construct(
        protected Site $site,
    ) {}

    /**
     * Make an authenticated request to the WordPress REST API.
     */
    public function request(string $method, string $endpoint, array $data = []): Response
    {
        $apiKey = $this->site->api_key;
        $apiSecret = $this->site->api_secret;
        $baseUrl = $this->site->api_endpoint ?: rtrim($this->site->url, '/') . '/wp-json/simplead/v1';

        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
        $timestamp = (string) time();
        $body = !empty($data) ? json_encode($data) : '';

        // Build the path portion for HMAC signing (must match WP_REST_Request::get_route())
        $path = '/simplead/v1/' . ltrim($endpoint, '/');

        $stringToSign = implode('|', [
            strtoupper($method),
            $path,
            $timestamp,
            $body,
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $apiSecret);

        $request = Http::withHeaders([
            'X-SAM-Key'       => $apiKey,
            'X-SAM-Timestamp' => $timestamp,
            'X-SAM-Signature' => $signature,
            'User-Agent'      => 'SimpleAD-Manager/1.0',
            'Accept'          => 'application/json',
        ])->timeout(30);

        if (strtoupper($method) === 'GET') {
            $response = $request->get($url);
        } else {
            $response = $request->withBody($body, 'application/json')->post($url);
        }

        if ($response->status() === 403 && str_contains($response->body(), 'Just a moment')) {
            throw new \RuntimeException(
                'Cloudflare is blocking API requests to this site. '
                . 'Add a WAF exception rule in Cloudflare for the path /wp-json/simplead/v1/* '
                . 'or whitelist this server\'s IP address.'
            );
        }

        return $response;
    }

    /**
     * Get site information.
     */
    public function getInfo(): array
    {
        $response = $this->request('GET', '/info');
        $response->throw();
        return $response->json();
    }

    /**
     * Get all plugins.
     */
    public function getPlugins(): array
    {
        $response = $this->request('GET', '/plugins');
        $response->throw();
        return $response->json();
    }

    /**
     * Get all themes.
     */
    public function getThemes(): array
    {
        $response = $this->request('GET', '/themes');
        $response->throw();
        return $response->json();
    }

    /**
     * Update one or more plugins.
     *
     * @param array $pluginFiles Array of plugin file paths (e.g., ['akismet/akismet.php'])
     */
    public function updatePlugins(array $pluginFiles): array
    {
        $response = $this->request('POST', '/plugins/update', [
            'plugins' => $pluginFiles,
        ]);
        $response->throw();
        return $response->json();
    }

    /**
     * Update one or more themes.
     *
     * @param array $themeSlugs Array of theme slugs
     */
    public function updateThemes(array $themeSlugs): array
    {
        $response = $this->request('POST', '/themes/update', [
            'themes' => $themeSlugs,
        ]);
        $response->throw();
        return $response->json();
    }

    /**
     * Activate a plugin.
     */
    public function activatePlugin(string $pluginFile): array
    {
        $response = $this->request('POST', '/plugins/activate', [
            'plugin' => $pluginFile,
        ]);
        $response->throw();
        return $response->json();
    }

    /**
     * Deactivate a plugin.
     */
    public function deactivatePlugin(string $pluginFile): array
    {
        $response = $this->request('POST', '/plugins/deactivate', [
            'plugin' => $pluginFile,
        ]);
        $response->throw();
        return $response->json();
    }

    /**
     * Delete a plugin.
     */
    public function deletePlugin(string $pluginFile): array
    {
        $response = $this->request('POST', '/plugins/delete', [
            'plugin' => $pluginFile,
        ]);
        $response->throw();
        return $response->json();
    }

    /**
     * Activate a theme.
     */
    public function activateTheme(string $themeSlug): array
    {
        $response = $this->request('POST', '/themes/activate', [
            'theme' => $themeSlug,
        ]);
        $response->throw();
        return $response->json();
    }

    /**
     * Delete a theme.
     */
    public function deleteTheme(string $themeSlug): array
    {
        $response = $this->request('POST', '/themes/delete', [
            'theme' => $themeSlug,
        ]);
        $response->throw();
        return $response->json();
    }

    /**
     * Get all users.
     */
    public function getUsers(): array
    {
        $response = $this->request('GET', '/users');
        $response->throw();
        return $response->json();
    }

    /**
     * Update WordPress core.
     */
    public function updateCore(): array
    {
        $response = $this->request('POST', '/core/update');
        $response->throw();
        return $response->json();
    }

    /**
     * Get a one-time login URL for WP Admin.
     */
    public function getLoginUrl(?string $user = null): array
    {
        $data = [];
        if ($user) {
            $data['user'] = $user;
        }

        $response = $this->request('POST', '/login-url', $data);
        $response->throw();
        return $response->json();
    }

    /**
     * Get core file integrity check data (file hashes).
     */
    public function getCoreIntegrityCheck(): array
    {
        $response = $this->request('GET', '/core-integrity-check');
        $response->throw();
        return $response->json();
    }

    /**
     * Get cron job list.
     */
    public function getCronList(): array
    {
        $response = $this->request('GET', '/cron-list');
        $response->throw();
        return $response->json();
    }

    /**
     * Run a specific cron hook.
     */
    public function runCron(string $hook, ?array $args = null): array
    {
        $data = ['hook' => $hook];
        if ($args !== null) {
            $data['args'] = $args;
        }
        $response = $this->request('POST', '/cron-run', $data);
        $response->throw();
        return $response->json();
    }

    /**
     * Disable a specific cron hook.
     */
    public function disableCron(string $hook, ?array $args = null): array
    {
        $data = ['hook' => $hook];
        if ($args !== null) {
            $data['args'] = $args;
        }
        $response = $this->request('POST', '/cron-disable', $data);
        $response->throw();
        return $response->json();
    }

    /**
     * Enable a specific cron hook.
     */
    public function enableCron(string $hook, string $schedule, ?array $args = null): array
    {
        $data = ['hook' => $hook, 'schedule' => $schedule];
        if ($args !== null) {
            $data['args'] = $args;
        }
        $response = $this->request('POST', '/cron-enable', $data);
        $response->throw();
        return $response->json();
    }

    /**
     * Get database cleanup stats preview.
     */
    public function getDbCleanupStats(): array
    {
        $response = $this->request('GET', '/db-cleanup-stats');
        $response->throw();
        return $response->json();
    }

    /**
     * Run database cleanup with specified options.
     */
    public function runDbCleanup(array $options): array
    {
        $response = $this->request('POST', '/db-cleanup-run', $options);
        $response->throw();
        return $response->json();
    }

    /**
     * Get error logs.
     */
    public function getErrorLogs(): array
    {
        $response = $this->request('GET', '/error-logs');
        $response->throw();
        return $response->json();
    }

    /**
     * Get database health information.
     */
    public function getDatabaseHealth(): array
    {
        $response = $this->request('GET', '/database-health');
        $response->throw();
        return $response->json();
    }

    /**
     * Perform a health check.
     */
    public function healthCheck(): array
    {
        $response = $this->request('GET', '/health');
        $response->throw();
        return $response->json();
    }

    /**
     * Get security check results.
     */
    public function getSecurityCheck(): array
    {
        $response = $this->request('GET', '/security-check');
        $response->throw();
        return $response->json();
    }

    /**
     * Apply a security fix.
     */
    public function applySecurityFix(string $key): array
    {
        $response = $this->request('POST', '/security-fix', [
            'key' => $key,
        ]);
        $response->throw();
        return $response->json();
    }

    /**
     * Get audit logs.
     */
    public function getAuditLogs(?string $since = null): array
    {
        $endpoint = '/audit-logs';
        if ($since) {
            $endpoint .= '?since=' . urlencode($since);
        }
        $response = $this->request('GET', $endpoint);
        $response->throw();
        return $response->json();
    }

    /**
     * Sync IP rules to the site.
     */
    public function syncIpRules(array $rules): array
    {
        $response = $this->request('POST', '/ip-rules/sync', [
            'rules' => $rules,
        ]);
        $response->throw();
        return $response->json();
    }

    /**
     * Get blocked requests from the site.
     */
    public function getBlockedRequests(?string $since = null): array
    {
        $endpoint = '/blocked-requests';
        if ($since) {
            $endpoint .= '?since=' . urlencode($since);
        }
        $response = $this->request('GET', $endpoint);
        $response->throw();
        return $response->json();
    }

    /**
     * Rollback a plugin, theme, or core to a previous version.
     */
    public function rollback(string $type, string $slug, string $version): array
    {
        $response = $this->request('POST', "/rollback/{$type}", [
            'slug' => $slug,
            'version' => $version,
        ]);
        $response->throw();
        return $response->json();
    }

    /**
     * Get server resource usage (CPU, memory, disk).
     */
    public function getServerResources(): array
    {
        $response = $this->request('GET', '/server-resources');
        $response->throw();
        return $response->json();
    }

    /**
     * Get SEO check data.
     */
    public function getSeoCheck(): array
    {
        $response = $this->request('GET', '/seo-check');
        $response->throw();
        return $response->json();
    }

    /**
     * Get WooCommerce stats.
     */
    public function getWooStats(?string $period = 'today'): array
    {
        $response = $this->request('GET', "/woo/stats?period={$period}");
        $response->throw();
        return $response->json();
    }

    /**
     * Get WooCommerce low stock products.
     */
    public function getWooLowStock(): array
    {
        $response = $this->request('GET', '/woo/low-stock');
        $response->throw();
        return $response->json();
    }

    /**
     * Get WooCommerce out of stock products.
     */
    public function getWooOutOfStock(): array
    {
        $response = $this->request('GET', '/woo/out-of-stock');
        $response->throw();
        return $response->json();
    }

    /**
     * Download a large file from the WordPress API (streaming).
     */
    public function streamDownload(string $endpoint, string $saveTo): void
    {
        $apiKey = $this->site->api_key;
        $apiSecret = $this->site->api_secret;
        $baseUrl = $this->site->api_endpoint ?: rtrim($this->site->url, '/') . '/wp-json/simplead/v1';

        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
        $timestamp = (string) time();

        $path = '/simplead/v1/' . ltrim($endpoint, '/');

        $stringToSign = implode('|', [
            'GET',
            $path,
            $timestamp,
            '',
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $apiSecret);

        $dir = dirname($saveTo);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $response = Http::withHeaders([
            'X-SAM-Key'       => $apiKey,
            'X-SAM-Timestamp' => $timestamp,
            'X-SAM-Signature' => $signature,
            'User-Agent'      => 'SimpleAD-Manager/1.0',
            'Accept'          => 'application/json',
        ])->timeout(600)->sink($saveTo)->get($url);

        $response->throw();
    }
}
