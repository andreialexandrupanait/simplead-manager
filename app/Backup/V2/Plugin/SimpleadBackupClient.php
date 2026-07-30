<?php

declare(strict_types=1);

namespace App\Backup\V2\Plugin;

use App\Backup\V2\Restore\RestoreClient;
use App\Backup\V2\Support\BackupLogger;
use App\Models\Site;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Throwable;

/**
 * HMAC-SHA256 REST client for the simplead-backup WP plugin.
 *
 * Signature (EXACTLY what SAM_Backup_Auth::validate() recomputes in the plugin):
 *
 *   string_to_sign = METHOD | ROUTE | TIMESTAMP | NONCE | BODY
 *   signature      = hash_hmac('sha256', string_to_sign, secret)   // hex
 *
 * where ROUTE is the WordPress REST route WITHOUT the /wp-json prefix and without
 * any query string (e.g. `/simplead-backup/v1/capabilities`), and BODY is the raw
 * request body byte-for-byte (empty string for a param-less call). A per-request
 * nonce is MANDATORY (the plugin rejects a missing nonce and any replay).
 *
 * Headers use the plugin's own X-SAM-Backup-* names (the plugin also accepts the
 * connector's X-SAM-* as a fallback; we send the dedicated ones).
 */
final class SimpleadBackupClient implements PluginClient, RestoreClient
{
    private const NAMESPACE = 'simplead-backup/v1';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly string $baseUrl,
        private readonly string $key,
        private readonly string $secret,
        private readonly int $timeout = 120,
        private readonly int $dbTimeBudget = 90,
        private readonly int $dbSegmentBytes = 8388608,
        private readonly ?BackupLogger $logger = null,
    ) {}

    /**
     * Lab factory: base URL is the WP host, creds from config('backup_v2.plugin.*')
     * (which default to the spike lab creds the plugin's own options are set to).
     */
    public static function lab(string $baseUrl, ?BackupLogger $logger = null): self
    {
        return new self(
            http: app(HttpFactory::class),
            baseUrl: $baseUrl,
            key: (string) config('backup_v2.plugin.key', 'spikekey12345'),
            secret: (string) config('backup_v2.plugin.secret', 'spikesecret67890'),
            timeout: (int) config('backup_v2.plugin.timeout', 120),
            dbTimeBudget: (int) config('backup_v2.plugin.db_time_budget', 90),
            dbSegmentBytes: (int) config('backup_v2.plugin.db_segment_bytes', 8388608),
            logger: $logger,
        );
    }

    /**
     * Production factory: signs against the site's own WordPress host with the
     * site's stored credentials.
     *
     * Base URL is `$site->url` (the plugin REST namespace `/wp-json/simplead-backup/v1/…`
     * is appended by sign()). Credentials come from the Site row: `api_key` /
     * `api_secret` are cast `encrypted`, so `$site->api_key` / `$site->api_secret`
     * are ALREADY decrypted on read — no manual decrypt() here, and no secret is
     * duplicated in code or config.
     *
     * Credential note: the simplead-backup plugin accepts the CONNECTOR credentials
     * (`sam_api_key` / `sam_api_secret`, the same pair the manager already HMAC-signs
     * connector calls with) as a fallback for its `X-SAM-Backup-*` auth. That is what
     * this factory uses today. Dedicated per-site plugin keys
     * (`sam_backup_api_key` / `sam_backup_api_secret`, stored separately from the
     * connector pair) are a follow-up: once the plugin provisions them they should be
     * resolved here in preference to the connector pair. Until then the connector
     * credentials authenticate the backup namespace.
     */
    public static function forSite(Site $site, ?BackupLogger $logger = null): self
    {
        return new self(
            http: app(HttpFactory::class),
            baseUrl: rtrim($site->url, '/'),
            key: (string) $site->api_key,
            secret: (string) $site->api_secret,
            timeout: (int) config('backup_v2.plugin.timeout', 120),
            dbTimeBudget: (int) config('backup_v2.plugin.db_time_budget', 90),
            dbSegmentBytes: (int) config('backup_v2.plugin.db_segment_bytes', 8388608),
            logger: $logger,
        );
    }

    public function capabilities(): array
    {
        return $this->json('POST', 'capabilities', []);
    }

    public function filesInventory(string $sessionId, array $rules, array $options = []): array
    {
        return $this->json('POST', 'files/inventory', array_merge([
            'session_id' => $sessionId,
            'rules' => $rules,
        ], $options));
    }

    public function dbDump(string $sessionId, array $params = []): array
    {
        return $this->json('POST', 'database/dump', array_merge([
            'session_id' => $sessionId,
            'time_budget' => $this->dbTimeBudget,
            'segment_bytes' => $this->dbSegmentBytes,
        ], $params));
    }

    public function dbChunkDownload(string $sessionId, int $chunkIndex, bool $deleteAfter): DownloadedChunk
    {
        return $this->download('database/chunk-download', [
            'session_id' => $sessionId,
            'chunk_index' => $chunkIndex,
            'delete' => $deleteAfter,
        ], $chunkIndex, 'v2db_');
    }

    public function filesChunkExec(string $sessionId, int $chunkIndex): array
    {
        return $this->json('POST', 'files/chunk-exec', [
            'session_id' => $sessionId,
            'chunk_index' => $chunkIndex,
        ]);
    }

    public function filesChunkDownload(string $sessionId, int $chunkIndex, bool $deleteAfter): DownloadedChunk
    {
        return $this->download('files/chunk-download', [
            'session_id' => $sessionId,
            'chunk_index' => $chunkIndex,
            'delete' => $deleteAfter,
        ], $chunkIndex, 'v2files_');
    }

    // ── restore (RestoreClient) ────────────────────────────────────────────

    public function restorePrepare(string $token, array $opts): array
    {
        return $this->json('POST', 'restore/prepare', array_merge(['token' => $token], $opts));
    }

    public function restoreStageChunk(string $token, string $kind, int $seq, string $localPath): array
    {
        if (! is_file($localPath)) {
            throw new PluginClientException("restore chunk source not found: {$localPath}");
        }

        // The raw chunk bytes ARE the signed body (METHOD|ROUTE|TS|NONCE|BODY), so a tampered
        // chunk fails the plugin's HMAC. Metadata rides as query params (not part of get_route()).
        $body = (string) file_get_contents($localPath);
        $sha = (string) hash_file('sha256', $localPath);
        $query = http_build_query([
            'token' => $token,
            'kind' => $kind,
            'seq' => $seq,
            'sha256' => $sha,
        ]);

        [$url, , $headers] = $this->sign('POST', 'restore/stage-chunk', [], $body);

        try {
            $response = $this->http
                ->withHeaders($headers)
                ->timeout($this->timeout)
                ->connectTimeout(15)
                ->withBody($body, 'application/octet-stream')
                ->send('POST', $url.'?'.$query);
        } catch (Throwable $e) {
            throw new PluginClientException("simplead-backup restore/stage-chunk transport error: {$e->getMessage()}");
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            $decoded = [];
        }
        if ($response->failed()) {
            throw new PluginClientException(
                "simplead-backup restore/stage-chunk returned HTTP {$response->status()}",
                $response->status(),
                $decoded,
            );
        }

        return $decoded;
    }

    public function restoreApply(string $token): array
    {
        return $this->json('POST', 'restore/apply', ['token' => $token]);
    }

    public function restoreCommit(string $token): array
    {
        return $this->json('POST', 'restore/commit', ['token' => $token]);
    }

    public function restoreRollback(string $token): array
    {
        return $this->json('POST', 'restore/rollback', ['token' => $token]);
    }

    public function restoreStatus(string $token): array
    {
        return $this->json('POST', 'restore/status', ['token' => $token]);
    }

    // ── internals ────────────────────────────────────────────────────────

    /**
     * Signed JSON request → decoded array. Throws PluginClientException on non-2xx.
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function json(string $method, string $path, array $params): array
    {
        [$url, $body, $headers] = $this->sign($method, $path, $params);

        try {
            $response = $this->request($headers, $body)->send($method, $url);
        } catch (Throwable $e) {
            throw new PluginClientException("simplead-backup {$path} transport error: {$e->getMessage()}");
        }

        $decoded = $response->json();
        if (! is_array($decoded)) {
            $decoded = [];
        }

        if ($response->failed()) {
            $this->logger?->warning('plugin request failed', [
                'path' => $path,
                'status' => $response->status(),
                'error' => $decoded['code'] ?? ($decoded['error'] ?? null),
            ]);

            throw new PluginClientException(
                "simplead-backup {$path} returned HTTP {$response->status()}",
                $response->status(),
                $decoded,
            );
        }

        return $decoded;
    }

    /**
     * Signed streaming download → local temp file wrapped in a DownloadedChunk.
     *
     * @param  array<string, mixed>  $params
     */
    private function download(string $path, array $params, int $chunkIndex, string $tmpPrefix): DownloadedChunk
    {
        [$url, $body, $headers] = $this->sign('POST', $path, $params);

        $tmp = (string) tempnam(sys_get_temp_dir(), $tmpPrefix);

        try {
            $response = $this->request($headers, $body)->sink($tmp)->send('POST', $url);
        } catch (Throwable $e) {
            @unlink($tmp);
            throw new PluginClientException("simplead-backup {$path} transport error: {$e->getMessage()}");
        }

        $status = $response->status();

        if ($status < 200 || $status >= 300) {
            // Non-2xx bodies are the plugin's JSON error, never chunk data. Surface
            // it (defence against the "store the 404 as if it were chunk data" trap).
            $payload = json_decode((string) @file_get_contents($tmp), true);
            @unlink($tmp);
            throw new PluginClientException(
                "simplead-backup {$path} download returned HTTP {$status}",
                $status,
                is_array($payload) ? $payload : [],
            );
        }

        return new DownloadedChunk(
            path: $tmp,
            chunkIndex: $chunkIndex,
            size: (int) filesize($tmp),
            sha256: (string) hash_file('sha256', $tmp),
            reportedSha256: $response->header('X-SAM-Chunk-Sha256') ?: null,
            httpStatus: $status,
            contentType: (string) $response->header('Content-Type'),
        );
    }

    /**
     * Build (url, body, headers) with a fresh timestamp + nonce + HMAC signature.
     *
     * When $rawBody is provided it is signed verbatim (used for the octet-stream
     * restore chunk upload, where the body is the chunk bytes, not JSON params).
     *
     * @param  array<string, mixed>  $params
     * @return array{0:string,1:string,2:array<string,string>}
     */
    private function sign(string $method, string $path, array $params, ?string $rawBody = null): array
    {
        $route = '/'.self::NAMESPACE.'/'.ltrim($path, '/');
        $body = $rawBody ?? ($params === [] ? '' : (string) json_encode($params, JSON_UNESCAPED_SLASHES));

        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $stringToSign = implode('|', [strtoupper($method), $route, $timestamp, $nonce, $body]);
        $signature = hash_hmac('sha256', $stringToSign, $this->secret);

        $url = rtrim($this->baseUrl, '/').'/wp-json'.$route;

        $headers = [
            'X-SAM-Backup-Key' => $this->key,
            'X-SAM-Backup-Timestamp' => $timestamp,
            'X-SAM-Backup-Nonce' => $nonce,
            'X-SAM-Backup-Signature' => $signature,
        ];

        return [$url, $body, $headers];
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function request(array $headers, string $body): PendingRequest
    {
        return $this->http
            ->withHeaders($headers)
            ->timeout($this->timeout)
            ->connectTimeout(15)
            ->withBody($body, 'application/json');
    }
}
