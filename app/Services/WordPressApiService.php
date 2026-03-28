<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WordPressApiServiceInterface;
use App\Models\Site;
use App\Services\WordPress\Concerns\ManagesCron;
use App\Services\WordPress\Concerns\ManagesDatabase;
use App\Services\WordPress\Concerns\ManagesPlugins;
use App\Services\WordPress\Concerns\ManagesSecurity;
use App\Services\WordPress\Concerns\ManagesSiteInfo;
use App\Services\WordPress\Concerns\ManagesThemes;
use App\Services\WordPress\Concerns\ManagesUsers;
use App\Services\WordPress\WordPressHttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Log;

class WordPressApiService implements WordPressApiServiceInterface
{
    use ManagesCron;
    use ManagesDatabase;
    use ManagesPlugins;
    use ManagesSecurity;
    use ManagesSiteInfo;
    use ManagesThemes;
    use ManagesUsers;

    protected WordPressHttpClient $http;

    public function __construct(
        protected Site $site,
    ) {
        $this->http = new WordPressHttpClient($site);
    }

    // ── Delegated HTTP methods (traits use these) ─────────────────

    public function setBackupMode(bool $enabled): void
    {
        $this->http->setBackupMode($enabled);
    }

    public function resetThrottle(): void
    {
        $this->http->resetThrottle();
    }

    public function request(string $method, string $endpoint, array $data = [], array $queryParams = [], int $timeout = 30): Response
    {
        return $this->http->request($method, $endpoint, $data, $queryParams, $timeout);
    }

    private function throwIfFailed(Response $response): void
    {
        $this->http->throwIfFailed($response);
    }

    public function requestRaw(string $method, string $endpoint, array $data = [], int $timeout = 30): Response
    {
        return $this->http->requestRaw($method, $endpoint, $data, $timeout);
    }

    /**
     * Get backup capabilities from the WP plugin.
     */
    public function getBackupCapabilities(): ?array
    {
        try {
            $response = $this->request('POST', '/backup/capabilities', [], [], 10);
            if (! $response->successful()) {
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
        if (! $initResponse->successful()) {
            throw new \RuntimeException('Chunked prepare-init failed: HTTP '.$initResponse->status());
        }

        $init = $initResponse->json();
        if (empty($init['success']) || empty($init['token'])) {
            throw new \RuntimeException('Chunked prepare-init returned non-success');
        }

        $token = $init['token'];
        Log::info('Chunked prepare-init OK for files (chunks mode): '.($init['total_chunks'] ?? '?').' chunks');

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
                if (! empty($init['success']) && ! empty($init['token'])) {
                    $chunkedInitAvailable = true;
                    Log::info("Chunked prepare-init OK for {$type}: ".($init['total_chunks'] ?? '?').' chunks, token: '.substr($init['token'], 0, 12).'...');
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
            Log::info("Chunked prepare-init returned non-success for {$type}: HTTP ".$initResponse->status());
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
            throw new \RuntimeException('Backup prepare failed: '.($prepare['error']['message'] ?? 'Unknown'));
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
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->setBackupMode(true);

        $fh = fopen($saveTo, 'wb');
        if (! $fh) {
            throw new \RuntimeException("Cannot open {$saveTo} for writing");
        }

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                // 1. Execute chunk on WP (creates .sql.gz chunk)
                $execResponse = $this->request('POST', '/backup/prepare-chunk-exec', [
                    'token' => $token,
                    'chunk_index' => $i,
                ], [], 300);

                if (! $execResponse->successful() || empty($execResponse->json()['success'])) {
                    $error = $execResponse->json()['error']['message'] ?? "HTTP {$execResponse->status()}";
                    throw new \RuntimeException("Chunk {$i} exec failed: {$error}");
                }

                $chunkSize = $execResponse->json()['chunk_size'] ?? 0;
                Log::info("DB chunk {$i}/{$totalChunks} executed on WP, size: {$chunkSize}");

                // 2. Download chunk and delete from WP
                $chunkTempFile = $saveTo.'.chunk_'.$i.'.tmp';
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
        [$url, $path] = $this->http->buildUrl('/backup/prepare-chunk-exec');
        $data = json_encode(['token' => $token, 'chunk_index' => $chunkIndex]);

        $headers = $this->http->buildAuthHeaders('POST', $path, $data);
        $headers['Content-Type'] = 'application/json';
        $headers['Accept'] = 'application/json';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $data,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $this->http->formatCurlHeaders($headers),
            CURLOPT_TIMEOUT => 300,
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

        if ($httpCode >= 400 || ! $response) {
            throw new \RuntimeException("Async exec failed (HTTP {$httpCode}): ".($error ?: substr($response ?? '', 0, 500)));
        }

        $data = json_decode($response, true);
        if (! is_array($data)) {
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
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->setBackupMode(true);
        $chunkFiles = [];

        try {
            $pendingExec = null; // Holds async exec handles for the next chunk

            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkTempFile = $saveTo.'.chunk_'.$i.'_files.zip';
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

                        if (! $execResponse->successful() || empty($execResponse->json()['success'])) {
                            $error = $execResponse->json()['error']['message'] ?? "HTTP {$execResponse->status()}";
                            throw new \RuntimeException("Files chunk {$i} exec failed: {$error}");
                        }

                        $chunkSize = $execResponse->json()['chunk_size'] ?? 0;
                        Log::info("Files chunk {$i}/{$totalChunks} executed on WP, size: {$chunkSize}".($chunkAttempt > 0 ? " (re-exec attempt {$chunkAttempt})" : ''));
                    }

                    if ($chunkSize === 0) {
                        Log::info("Files chunk {$i}/{$totalChunks} is empty, skipping download");
                        break;
                    }

                    // Pipeline: start async exec for next chunk BEFORE downloading current
                    if ($i + 1 < $totalChunks) {
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
                                try {
                                    $this->waitAsyncExec($pendingExec);
                                } catch (\Throwable) {
                                }
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
                Log::info("Files chunk {$i}/{$totalChunks} downloaded (".round(filesize($chunkTempFile) / 1048576, 1).' MB)');
                $this->resetThrottle();

                if ($onProgress) {
                    $onProgress($i + 1, $totalChunks);
                }
            }

            // Clean up any remaining pending exec
            if ($pendingExec !== null) {
                try {
                    $this->waitAsyncExec($pendingExec);
                } catch (\Throwable) {
                }
            }
        } finally {
            $this->setBackupMode(false);
        }

        if (empty($chunkFiles)) {
            throw new \RuntimeException('Files backup produced no chunks');
        }

        Log::info('Files backup: '.count($chunkFiles).' chunk zips ready for direct archive storage');

        // Cleanup session on WP
        try {
            $this->request('POST', '/backup/cleanup', ['token' => $token], [], 10);
        } catch (\Throwable) {
        }

        return $chunkFiles;
    }

    public function streamDownloadTo(string $endpoint, array $data, string $saveTo, int $maxRetries = 5): void
    {
        $this->http->streamDownloadTo($endpoint, $data, $saveTo, $maxRetries);
    }

    /**
     * Download a prepared backup file from WP in 25MB chunks with checksum verification.
     */
    private function downloadPreparedFile(string $token, int $totalSize, string $expectedChecksum, string $saveTo, ?callable $onProgress = null): void
    {
        $chunkSize = 25 * 1024 * 1024;
        $offset = 0;

        $dir = dirname($saveTo);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $fh = fopen($saveTo, 'wb');
        if (! $fh) {
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

    public function streamDownload(string $endpoint, string $saveTo): void
    {
        $this->http->streamDownload($endpoint, $saveTo);
    }
}
