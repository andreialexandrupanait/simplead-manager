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
    public function request(string $method, string $endpoint, array $data = [], array $queryParams = [], int $timeout = 30): Response
    {
        $apiKey = $this->site->api_key;
        $apiSecret = $this->site->api_secret;
        $baseUrl = $this->site->api_endpoint ?: rtrim($this->site->url, '/') . '/wp-json/simplead/v1';

        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
        if (!empty($queryParams)) {
            $url .= '?' . http_build_query($queryParams);
        }
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $body = !empty($data) ? json_encode($data) : '';

        // Use only the clean path for HMAC signing (WP_REST_Request::get_route() excludes query params)
        $path = '/simplead/v1/' . ltrim($endpoint, '/');

        // v2.0 format: METHOD|PATH|TIMESTAMP|NONCE|BODY
        $stringToSign = implode('|', [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            $body,
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $apiSecret);

        $request = Http::withHeaders([
            'X-SAM-Key'       => $apiKey,
            'X-SAM-Timestamp' => $timestamp,
            'X-SAM-Nonce'     => $nonce,
            'X-SAM-Signature' => $signature,
            'User-Agent'      => 'SimpleAD-Manager/2.0',
            'Accept'          => 'application/json',
        ])->timeout($timeout);

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
     * Extract a meaningful error message from a failed WordPress API response and throw.
     */
    private function throwIfFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $json = $response->json();
        $message = $json['error']['message']
            ?? $json['message']
            ?? null;

        if ($message) {
            throw new \RuntimeException($message);
        }

        // Fall back to Laravel's default throw (includes status code)
        $response->throw();
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
        $this->throwIfFailed($response);
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
        $this->throwIfFailed($response);
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
        $this->throwIfFailed($response);
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
        $this->throwIfFailed($response);
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
        $this->throwIfFailed($response);
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
        $this->throwIfFailed($response);
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
        $this->throwIfFailed($response);
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
     * Clear WordPress caches (object cache, transients, plugin caches).
     */
    public function clearCache(): array
    {
        $response = $this->request('POST', '/cache-clear');
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
     * Push security settings to the plugin.
     */
    public function pushSecuritySettings(array $settings): array
    {
        $response = $this->request('POST', '/security-settings', $settings);
        $this->throwIfFailed($response);
        return $response->json();
    }

    /**
     * Get the full security state from the plugin.
     */
    public function getSecurityState(): array
    {
        $response = $this->request('GET', '/security-state');
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
     * Make an authenticated request returning a raw Response (for binary data).
     */
    public function requestRaw(string $method, string $endpoint, array $data = [], int $timeout = 30): Response
    {
        $apiKey = $this->site->api_key;
        $apiSecret = $this->site->api_secret;
        $baseUrl = $this->site->api_endpoint ?: rtrim($this->site->url, '/') . '/wp-json/simplead/v1';

        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $body = !empty($data) ? json_encode($data) : '';

        $path = '/simplead/v1/' . ltrim($endpoint, '/');

        $stringToSign = implode('|', [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            $body,
        ]);

        $signature = hash_hmac('sha256', $stringToSign, $apiSecret);

        $request = Http::withHeaders([
            'X-SAM-Key'       => $apiKey,
            'X-SAM-Timestamp' => $timestamp,
            'X-SAM-Nonce'     => $nonce,
            'X-SAM-Signature' => $signature,
            'User-Agent'      => 'SimpleAD-Manager/2.0',
        ])->timeout($timeout);

        if (strtoupper($method) === 'GET') {
            $response = $request->get($url);
        } else {
            $response = $request->withBody($body, 'application/json')->post($url);
        }

        $response->throw();

        return $response;
    }

    /**
     * Get backup capabilities from the WP plugin.
     */
    public function getBackupCapabilities(): ?array
    {
        try {
            $response = $this->request('POST', '/backup/capabilities', [], [], 10);
            if (!$response->successful()) {
                return null;
            }
            $data = $response->json();
            return $data['success'] ?? false ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Download a backup file in chunks using the WP plugin's /backup/prepare + /backup/chunk endpoints.
     */
    public function chunkedDownload(string $type, string $saveTo, ?callable $onProgress = null): void
    {
        // Step 1: Prepare — creates archive on WP, returns token/size/checksum
        $prepareResponse = $this->request('POST', '/backup/prepare', ['type' => $type], [], 600);
        $prepareResponse->throw();
        $prepare = $prepareResponse->json();

        if (empty($prepare['success']) || empty($prepare['token'])) {
            throw new \RuntimeException('Backup prepare failed: ' . ($prepare['error']['message'] ?? 'Unknown'));
        }

        $token = $prepare['token'];
        $totalSize = (int) $prepare['size'];
        $expectedChecksum = $prepare['checksum'];

        // Step 2: Download in 25MB chunks
        $chunkSize = 25 * 1024 * 1024;
        $offset = 0;

        $dir = dirname($saveTo);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fh = fopen($saveTo, 'wb');
        if (!$fh) {
            throw new \RuntimeException("Cannot open {$saveTo} for writing");
        }

        try {
            while ($offset < $totalSize) {
                $length = min($chunkSize, $totalSize - $offset);

                $chunkResponse = $this->requestRaw('POST', '/backup/chunk', [
                    'token' => $token,
                    'offset' => $offset,
                    'length' => $length,
                ], 120);

                $chunk = $chunkResponse->body();
                $written = fwrite($fh, $chunk);
                if ($written === false) {
                    throw new \RuntimeException("Failed to write chunk at offset {$offset}");
                }

                $offset += strlen($chunk);

                if ($onProgress) {
                    $onProgress($offset, $totalSize);
                }
            }
        } finally {
            fclose($fh);
        }

        // Step 3: Verify checksum
        $actualChecksum = hash_file('sha256', $saveTo);
        if ($actualChecksum !== $expectedChecksum) {
            @unlink($saveTo);
            throw new \RuntimeException("Checksum mismatch: expected {$expectedChecksum}, got {$actualChecksum}");
        }

        // Step 4: Cleanup prepared file on WP
        try {
            $this->request('POST', '/backup/cleanup', ['token' => $token], [], 10);
        } catch (\Throwable) {
            // Best effort
        }
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
        $nonce = bin2hex(random_bytes(16));

        $path = '/simplead/v1/' . ltrim($endpoint, '/');

        // v2.0 format: METHOD|PATH|TIMESTAMP|NONCE|BODY
        $stringToSign = implode('|', [
            'POST',
            $path,
            $timestamp,
            $nonce,
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
            'X-SAM-Nonce'     => $nonce,
            'X-SAM-Signature' => $signature,
            'User-Agent'      => 'SimpleAD-Manager/2.0',
        ])->timeout(1800)->withBody('', 'application/json')->sink($saveTo)->post($url);

        $response->throw();
    }
}
