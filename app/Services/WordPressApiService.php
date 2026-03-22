<?php

namespace App\Services;

use App\Models\Site;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WordPressApiService
{
    private float $lastRequestTime = 0;
    private float $minRequestInterval = 1.5; // seconds - max ~40 req/min (33% headroom under 60 limit)
    private float $baseRequestInterval = 1.5;

    public function __construct(
        protected Site $site,
    ) {}

    /**
     * Throttle requests to avoid 429 rate limiting.
     * Ensures minimum interval between consecutive requests.
     */
    private function throttle(): void
    {
        if ($this->lastRequestTime > 0) {
            $elapsed = microtime(true) - $this->lastRequestTime;
            if ($elapsed < $this->minRequestInterval) {
                $sleepUs = (int) (($this->minRequestInterval - $elapsed) * 1_000_000);
                usleep($sleepUs);
            }
        }
        $this->lastRequestTime = microtime(true);
    }

    /**
     * Increase throttle delay after encountering rate limiting.
     */
    private function backoffThrottle(): void
    {
        $this->minRequestInterval = min($this->minRequestInterval * 2, 15.0);
        Log::info("Throttle increased to {$this->minRequestInterval}s after rate limit");
    }

    /**
     * Gradually restore throttle after successful requests.
     */
    private function relaxThrottle(): void
    {
        if ($this->minRequestInterval > $this->baseRequestInterval) {
            $this->minRequestInterval = max($this->minRequestInterval * 0.8, $this->baseRequestInterval);
        }
    }

    /**
     * Enable backup mode with reduced throttle interval.
     */
    public function setBackupMode(bool $enabled): void
    {
        $this->baseRequestInterval = $enabled ? 0.5 : 1.5;
        $this->minRequestInterval = $this->baseRequestInterval;
    }

    /**
     * Reset throttle to base interval (use after each successful chunk cycle
     * so a 429 during chunk N doesn't penalize chunk N+1).
     */
    public function resetThrottle(): void
    {
        $this->minRequestInterval = $this->baseRequestInterval;
    }

    /**
     * Make an authenticated request to the WordPress REST API.
     */
    public function request(string $method, string $endpoint, array $data = [], array $queryParams = [], int $timeout = 30): Response
    {
        $apiKey = $this->site->api_key;
        $apiSecret = $this->site->api_secret;
        $baseUrl = $this->site->api_endpoint ?: rtrim($this->site->url, '/') . '/wp-json/simplead/v1';

        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
        // Always add cache-busting parameter to bypass CDN/Cloudflare caching
        $queryParams['_nocache'] = time();
        $url .= '?' . http_build_query($queryParams);
        $body = !empty($data) ? json_encode($data) : '';

        // Use only the clean path for HMAC signing (WP_REST_Request::get_route() excludes query params)
        $path = '/simplead/v1/' . ltrim($endpoint, '/');

        $maxRetries = 5;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $this->throttle();

            // Fresh timestamp/nonce for each attempt (stale timestamps get rejected)
            $timestamp = (string) time();
            $nonce = bin2hex(random_bytes(16));

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

            if ($response->status() === 429 && $attempt < $maxRetries) {
                $retryAfter = (int) $response->header('Retry-After') ?: min(10 * pow(2, $attempt), 120);
                $retryAfter = min(max($retryAfter, 5), 120);
                Log::warning("Rate limited (429) on {$endpoint}, retry " . ($attempt + 1) . "/{$maxRetries} after {$retryAfter}s");
                $this->backoffThrottle();
                sleep($retryAfter);
                continue;
            }

            $this->relaxThrottle();
            break;
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
     * Create a WordPress user.
     */
    public function createUser(array $data): array
    {
        $response = $this->request('POST', '/users/create', $data);
        $this->throwIfFailed($response);
        return $response->json();
    }

    /**
     * Update a WordPress user.
     */
    public function updateUser(int $wpUserId, array $data): array
    {
        $response = $this->request('POST', '/users/update', array_merge($data, [
            'wp_user_id' => $wpUserId,
        ]));
        $this->throwIfFailed($response);
        return $response->json();
    }

    /**
     * Delete a WordPress user.
     */
    public function deleteUser(int $wpUserId, ?int $reassignTo = null): array
    {
        $data = ['wp_user_id' => $wpUserId];
        if ($reassignTo !== null) {
            $data['reassign_to'] = $reassignTo;
        }
        $response = $this->request('POST', '/users/delete', $data);
        $this->throwIfFailed($response);
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
     * Get server resource usage (CPU, RAM, disk).
     */
    public function getServerResources(): array
    {
        $response = $this->request('GET', '/server-resources');
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
     * Run site diagnostic checks.
     */
    public function runDiagnostic(): array
    {
        $response = $this->request('GET', '/diagnostic');
        $response->throw();
        return $response->json();
    }

    /**
     * Fix Elementor version mismatch after restore.
     */
    public function fixElementor(): array
    {
        $response = $this->request('POST', '/diagnostic/fix-elementor');
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
        $body = !empty($data) ? json_encode($data) : '';
        $path = '/simplead/v1/' . ltrim($endpoint, '/');

        $maxRetries = 5;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $this->throttle();

            // Fresh timestamp/nonce for each attempt
            $timestamp = (string) time();
            $nonce = bin2hex(random_bytes(16));

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

            if ($response->status() === 429 && $attempt < $maxRetries) {
                $retryAfter = (int) $response->header('Retry-After') ?: min(10 * pow(2, $attempt), 120);
                $retryAfter = min(max($retryAfter, 5), 120);
                Log::warning("Rate limited (429) on raw {$endpoint}, retry " . ($attempt + 1) . "/{$maxRetries} after {$retryAfter}s");
                $this->backoffThrottle();
                sleep($retryAfter);
                continue;
            }

            $this->relaxThrottle();
            break;
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
     * Download files backup in chunks, returning [chunkPaths, sessionToken].
     * Used by CreateBackup to store chunks directly in the outer archive (v2 format).
     */
    public function chunkedDownloadFilesAsChunks(string $saveTo, ?callable $onProgress = null): array
    {
        $initResponse = $this->request('POST', '/backup/prepare-init', ['type' => 'files'], [], 30);
        if (!$initResponse->successful()) {
            throw new \RuntimeException('Chunked prepare-init failed: HTTP ' . $initResponse->status());
        }

        $init = $initResponse->json();
        if (empty($init['success']) || empty($init['token'])) {
            throw new \RuntimeException('Chunked prepare-init returned non-success');
        }

        $token = $init['token'];
        Log::info("Chunked prepare-init OK for files (chunks mode): " . ($init['total_chunks'] ?? '?') . " chunks");

        $chunkPaths = $this->chunkedPrepareAndDownloadFilesAsChunks(
            $token,
            (int) $init['total_chunks'],
            $saveTo,
            $onProgress
        );

        return [$chunkPaths, $token];
    }

    /**
     * Download a backup file in chunks using the WP plugin's prepare + chunk endpoints.
     * Tries chunked prepare first (multi-request, works within timeout limits),
     * falls back to sync prepare for older plugin versions.
     */
    public function chunkedDownload(string $type, string $saveTo, ?callable $onProgress = null): void
    {
        // Try chunked prepare+download (each chunk downloaded immediately, minimal WP /tmp usage)
        $chunkedInitAvailable = false;
        try {
            $initResponse = $this->request('POST', '/backup/prepare-init', ['type' => $type], [], 30);
            if ($initResponse->successful()) {
                $init = $initResponse->json();
                if (!empty($init['success']) && !empty($init['token'])) {
                    $chunkedInitAvailable = true;
                    Log::info("Chunked prepare-init OK for {$type}: " . ($init['total_chunks'] ?? '?') . " chunks, token: " . substr($init['token'], 0, 12) . '...');
                    $this->chunkedPrepareAndDownload(
                        $init['token'],
                        $init['type'] ?? $type,
                        (int) $init['total_chunks'],
                        $saveTo,
                        $onProgress
                    );
                    return;
                }
            }
            Log::info("Chunked prepare-init returned non-success for {$type}: HTTP " . $initResponse->status());
        } catch (\Throwable $e) {
            if ($chunkedInitAvailable) {
                // Chunked init worked but execution/download failed — don't fall back to sync
                // which will also fail on large sites. Re-throw the actual error.
                Log::error("Chunked prepare+download failed for {$type} (NOT falling back to sync): {$e->getMessage()}");
                throw $e;
            }
            Log::info("Chunked prepare not available for {$type}: {$e->getMessage()}, falling back to sync");
        }

        // Fallback: sync prepare (single request, may timeout on restrictive hosts)
        $prepareResponse = $this->request('POST', '/backup/prepare', ['type' => $type], [], 600);
        $prepareResponse->throw();
        $prepare = $prepareResponse->json();

        if (empty($prepare['success']) || empty($prepare['token'])) {
            throw new \RuntimeException('Backup prepare failed: ' . ($prepare['error']['message'] ?? 'Unknown'));
        }

        $token = $prepare['token'];
        $totalSize = (int) $prepare['size'];
        $expectedChecksum = $prepare['checksum'];

        // Download the prepared file in 25MB chunks
        $this->downloadPreparedFile($token, $totalSize, $expectedChecksum, $saveTo, $onProgress);
    }

    /**
     * Chunked prepare + download for DB.
     * Exec each chunk, download it immediately (concatenable .sql.gz), delete from WP.
     */
    private function chunkedPrepareAndDownload(string $token, string $type, int $totalChunks, string $saveTo, ?callable $onProgress = null): void
    {
        $this->chunkedPrepareAndDownloadDb($token, $totalChunks, $saveTo, $onProgress);
    }

    /**
     * DB chunked: exec each chunk, download immediately, concatenate locally.
     */
    private function chunkedPrepareAndDownloadDb(string $token, int $totalChunks, string $saveTo, ?callable $onProgress = null): void
    {
        $dir = dirname($saveTo);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->setBackupMode(true);

        $fh = fopen($saveTo, 'wb');
        if (!$fh) {
            throw new \RuntimeException("Cannot open {$saveTo} for writing");
        }

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                // 1. Execute chunk on WP (creates .sql.gz chunk)
                $execResponse = $this->request('POST', '/backup/prepare-chunk-exec', [
                    'token' => $token,
                    'chunk_index' => $i,
                ], [], 300);

                if (!$execResponse->successful() || empty($execResponse->json()['success'])) {
                    $error = $execResponse->json()['error']['message'] ?? "HTTP {$execResponse->status()}";
                    throw new \RuntimeException("Chunk {$i} exec failed: {$error}");
                }

                $chunkSize = $execResponse->json()['chunk_size'] ?? 0;
                Log::info("DB chunk {$i}/{$totalChunks} executed on WP, size: {$chunkSize}");

                // 2. Download chunk and delete from WP
                $chunkTempFile = $saveTo . '.chunk_' . $i . '.tmp';
                $this->streamDownloadTo('/backup/prepare-chunk-download', [
                    'token' => $token,
                    'chunk_index' => $i,
                    'delete' => true,
                ], $chunkTempFile);

                // 3. Append to final file (gzip concatenation is valid)
                $cfh = fopen($chunkTempFile, 'rb');
                if ($cfh) {
                    stream_copy_to_stream($cfh, $fh);
                    fclose($cfh);
                }
                @unlink($chunkTempFile);

                Log::info("DB chunk {$i}/{$totalChunks} downloaded and appended");
                $this->resetThrottle();

                if ($onProgress) {
                    $onProgress($i + 1, $totalChunks);
                }
            }
        } finally {
            fclose($fh);
            $this->setBackupMode(false);
        }

        // Verify we got data
        clearstatcache(true, $saveTo);
        if (filesize($saveTo) === 0) {
            @unlink($saveTo);
            throw new \RuntimeException('Backup file is empty after chunked download');
        }

        // Cleanup session on WP
        try {
            $this->request('POST', '/backup/cleanup', ['token' => $token], [], 10);
        } catch (\Throwable) {
            // Best effort
        }
    }

    /**
     * Start an async exec request using curl_multi (non-blocking).
     * Returns the curl handles needed to wait for completion.
     */
    private function startAsyncExec(string $token, int $chunkIndex): array
    {
        $apiKey = $this->site->api_key;
        $apiSecret = $this->site->api_secret;
        $baseUrl = $this->site->api_endpoint ?: rtrim($this->site->url, '/') . '/wp-json/simplead/v1';

        $url = rtrim($baseUrl, '/') . '/backup/prepare-chunk-exec';
        $data = json_encode(['token' => $token, 'chunk_index' => $chunkIndex]);
        $path = '/simplead/v1/backup/prepare-chunk-exec';

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $stringToSign = implode('|', ['POST', $path, $timestamp, $nonce, $data]);
        $signature = hash_hmac('sha256', $stringToSign, $apiSecret);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'X-SAM-Key: ' . $apiKey,
                'X-SAM-Timestamp: ' . $timestamp,
                'X-SAM-Nonce: ' . $nonce,
                'X-SAM-Signature: ' . $signature,
                'User-Agent: SimpleAD-Manager/2.0',
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT        => 300,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $mh = curl_multi_init();
        curl_multi_add_handle($mh, $ch);

        // Start execution (non-blocking)
        $running = null;
        curl_multi_exec($mh, $running);

        return ['mh' => $mh, 'ch' => $ch, 'chunk_index' => $chunkIndex];
    }

    /**
     * Wait for an async exec to complete and return the parsed response.
     */
    private function waitAsyncExec(array $handles): array
    {
        $mh = $handles['mh'];
        $ch = $handles['ch'];

        // Wait for completion
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        $response = curl_multi_getcontent($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_multi_remove_handle($mh, $ch);
        curl_close($ch);
        curl_multi_close($mh);

        if ($httpCode >= 400 || !$response) {
            throw new \RuntimeException("Async exec failed (HTTP {$httpCode}): " . ($error ?: substr($response ?? '', 0, 500)));
        }

        $data = json_decode($response, true);
        if (!is_array($data)) {
            throw new \RuntimeException("Async exec returned invalid JSON (HTTP {$httpCode})");
        }

        return $data;
    }

    /**
     * Files chunked: exec each chunk on WP, download individually, return chunk paths.
     * Pipelines exec(N+1) while downloading chunk N for maximum throughput.
     */
    private function chunkedPrepareAndDownloadFilesAsChunks(string $token, int $totalChunks, string $saveTo, ?callable $onProgress = null): array
    {
        $dir = dirname($saveTo);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->setBackupMode(true);
        $chunkFiles = [];

        try {
            $pendingExec = null; // Holds async exec handles for the next chunk

            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkTempFile = $saveTo . '.chunk_' . $i . '_files.zip';
                $maxChunkAttempts = 2;
                $chunkSize = 0;

                for ($chunkAttempt = 0; $chunkAttempt < $maxChunkAttempts; $chunkAttempt++) {
                    // Get exec result: either from pending async or start a new sync one
                    if ($pendingExec !== null && $pendingExec['chunk_index'] === $i) {
                        // Wait for the pipelined async exec
                        try {
                            $execData = $this->waitAsyncExec($pendingExec);
                            $pendingExec = null;

                            if (empty($execData['success'])) {
                                $error = $execData['error']['message'] ?? 'Unknown error';
                                throw new \RuntimeException("Files chunk {$i} exec failed: {$error}");
                            }

                            $chunkSize = $execData['chunk_size'] ?? 0;
                            Log::info("Files chunk {$i}/{$totalChunks} executed on WP (pipelined), size: {$chunkSize}");
                        } catch (\RuntimeException $e) {
                            $pendingExec = null;
                            // Fall through to sync retry if we have attempts left
                            if ($chunkAttempt + 1 >= $maxChunkAttempts) {
                                throw $e;
                            }
                            Log::warning("Pipelined exec for chunk {$i} failed, retrying sync: {$e->getMessage()}");
                            continue;
                        }
                    } else {
                        // Sync exec (first chunk, or retry after failure)
                        $pendingExec = null;
                        $execResponse = $this->request('POST', '/backup/prepare-chunk-exec', [
                            'token' => $token,
                            'chunk_index' => $i,
                        ], [], 300);

                        if (!$execResponse->successful() || empty($execResponse->json()['success'])) {
                            $error = $execResponse->json()['error']['message'] ?? "HTTP {$execResponse->status()}";
                            throw new \RuntimeException("Files chunk {$i} exec failed: {$error}");
                        }

                        $chunkSize = $execResponse->json()['chunk_size'] ?? 0;
                        Log::info("Files chunk {$i}/{$totalChunks} executed on WP, size: {$chunkSize}" . ($chunkAttempt > 0 ? " (re-exec attempt {$chunkAttempt})" : ''));
                    }

                    if ($chunkSize === 0) {
                        Log::info("Files chunk {$i}/{$totalChunks} is empty, skipping download");
                        break;
                    }

                    // Pipeline: start async exec for next chunk BEFORE downloading current
                    if ($i + 1 < $totalChunks && $pendingExec === null) {
                        $pendingExec = $this->startAsyncExec($token, $i + 1);
                    }

                    try {
                        $this->streamDownloadTo('/backup/prepare-chunk-download', [
                            'token' => $token,
                            'chunk_index' => $i,
                            'delete' => true,
                        ], $chunkTempFile);
                        break;
                    } catch (\RuntimeException $e) {
                        if ($chunkAttempt + 1 < $maxChunkAttempts && str_contains($e->getMessage(), 'HTTP 404')) {
                            Log::warning("Files chunk {$i} disappeared, re-executing...");
                            @unlink($chunkTempFile);
                            sleep(2);
                            // Cancel pending async exec since we're retrying
                            if ($pendingExec !== null) {
                                try { $this->waitAsyncExec($pendingExec); } catch (\Throwable) {}
                                $pendingExec = null;
                            }
                            continue;
                        }
                        throw $e;
                    }
                }

                if ($chunkSize === 0) {
                    if ($onProgress) {
                        $onProgress($i + 1, $totalChunks);
                    }
                    continue;
                }

                $chunkFiles[] = $chunkTempFile;
                Log::info("Files chunk {$i}/{$totalChunks} downloaded (" . round(filesize($chunkTempFile) / 1048576, 1) . " MB)");
                $this->resetThrottle();

                if ($onProgress) {
                    $onProgress($i + 1, $totalChunks);
                }
            }

            // Clean up any remaining pending exec
            if ($pendingExec !== null) {
                try { $this->waitAsyncExec($pendingExec); } catch (\Throwable) {}
            }
        } finally {
            $this->setBackupMode(false);
        }

        if (empty($chunkFiles)) {
            throw new \RuntimeException('Files backup produced no chunks');
        }

        Log::info("Files backup: " . count($chunkFiles) . " chunk zips ready for direct archive storage");

        // Cleanup session on WP
        try {
            $this->request('POST', '/backup/cleanup', ['token' => $token], [], 10);
        } catch (\Throwable) {
        }

        return $chunkFiles;
    }

    /**
     * Stream-download from a WP endpoint to a local file (for binary data with POST body).
     * Retries up to 5 times on 429 rate limiting with exponential backoff.
     */
    private function streamDownloadTo(string $endpoint, array $data, string $saveTo, int $maxRetries = 5): void
    {
        $apiKey = $this->site->api_key;
        $apiSecret = $this->site->api_secret;
        $baseUrl = $this->site->api_endpoint ?: rtrim($this->site->url, '/') . '/wp-json/simplead/v1';

        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpoint, '/');
        $body = !empty($data) ? json_encode($data) : '';
        $path = '/simplead/v1/' . ltrim($endpoint, '/');

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $this->throttle();

            // Fresh timestamp/nonce for each attempt
            $timestamp = (string) time();
            $nonce = bin2hex(random_bytes(16));
            $stringToSign = implode('|', ['POST', $path, $timestamp, $nonce, $body]);
            $signature = hash_hmac('sha256', $stringToSign, $apiSecret);

            $fh = fopen($saveTo, 'wb');
            if (!$fh) {
                throw new \RuntimeException("Cannot open {$saveTo} for writing");
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_FILE           => $fh,
                CURLOPT_HTTPHEADER     => [
                    'X-SAM-Key: ' . $apiKey,
                    'X-SAM-Timestamp: ' . $timestamp,
                    'X-SAM-Nonce: ' . $nonce,
                    'X-SAM-Signature: ' . $signature,
                    'User-Agent: SimpleAD-Manager/2.0',
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT        => 600,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            fclose($fh);

            if ($httpCode === 429 && $attempt < $maxRetries) {
                $retryAfter = min(10 * pow(2, $attempt), 120);
                Log::warning("Rate limited (429) on stream download {$endpoint}, retry " . ($attempt + 1) . "/{$maxRetries} after {$retryAfter}s");
                $this->backoffThrottle();
                @unlink($saveTo);
                sleep($retryAfter);
                continue;
            }

            $this->relaxThrottle();
            break;
        }

        if (!$success || $httpCode >= 400) {
            // Read only first 1KB for error details (file could be huge)
            $efh = fopen($saveTo, 'rb');
            $errorBody = $efh ? fread($efh, 1024) : '';
            if ($efh) fclose($efh);
            @unlink($saveTo);
            throw new \RuntimeException("Chunk download failed (HTTP {$httpCode}): " . ($error ?: substr($errorBody, 0, 500)));
        }

        clearstatcache(true, $saveTo);
        if (filesize($saveTo) === 0) {
            @unlink($saveTo);
            throw new \RuntimeException("Chunk download returned empty file (HTTP {$httpCode})");
        }
    }

    /**
     * Download a prepared backup file from WP in 25MB chunks with checksum verification.
     */
    private function downloadPreparedFile(string $token, int $totalSize, string $expectedChecksum, string $saveTo, ?callable $onProgress = null): void
    {
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

        // Verify checksum
        $actualChecksum = hash_file('sha256', $saveTo);
        if ($actualChecksum !== $expectedChecksum) {
            @unlink($saveTo);
            throw new \RuntimeException("Checksum mismatch: expected {$expectedChecksum}, got {$actualChecksum}");
        }

        // Cleanup prepared file on WP
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
        $path = '/simplead/v1/' . ltrim($endpoint, '/');

        $dir = dirname($saveTo);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $maxRetries = 5;
        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $this->throttle();

            // Fresh timestamp/nonce for each attempt
            $timestamp = (string) time();
            $nonce = bin2hex(random_bytes(16));
            $stringToSign = implode('|', ['POST', $path, $timestamp, $nonce, '']);
            $signature = hash_hmac('sha256', $stringToSign, $apiSecret);

            // Use curl directly — WP endpoints use readfile()+exit which Http::sink() doesn't handle
            $fh = fopen($saveTo, 'wb');
            if (!$fh) {
                throw new \RuntimeException("Cannot open {$saveTo} for writing");
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => '',
                CURLOPT_FILE           => $fh,
                CURLOPT_HTTPHEADER     => [
                    'X-SAM-Key: ' . $apiKey,
                    'X-SAM-Timestamp: ' . $timestamp,
                    'X-SAM-Nonce: ' . $nonce,
                    'X-SAM-Signature: ' . $signature,
                    'User-Agent: SimpleAD-Manager/2.0',
                    'Content-Type: application/json',
                ],
                CURLOPT_TIMEOUT        => 1800,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $success = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            fclose($fh);

            if ($httpCode === 429 && $attempt < $maxRetries) {
                $retryAfter = min(10 * pow(2, $attempt), 120);
                Log::warning("Rate limited (429) on stream download {$endpoint}, retry " . ($attempt + 1) . "/{$maxRetries} after {$retryAfter}s");
                $this->backoffThrottle();
                @unlink($saveTo);
                sleep($retryAfter);
                continue;
            }

            $this->relaxThrottle();
            break;
        }

        if (!$success || $httpCode >= 400) {
            $body = file_get_contents($saveTo);
            @unlink($saveTo);
            throw new \RuntimeException("Stream download failed (HTTP {$httpCode}): " . ($error ?: substr($body, 0, 500)));
        }

        $size = filesize($saveTo);
        if ($size === 0) {
            @unlink($saveTo);
            throw new \RuntimeException("Stream download returned empty file (HTTP {$httpCode})");
        }
    }
}
