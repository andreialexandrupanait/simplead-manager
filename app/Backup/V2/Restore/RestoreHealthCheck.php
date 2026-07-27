<?php

declare(strict_types=1);

namespace App\Backup\V2\Restore;

use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Support\BackupLogger;
use App\Models\Site;
use Illuminate\Http\Client\Factory as HttpFactory;
use Throwable;

/**
 * REAL post-restore health probe handed to RestoreRunner as its `healthCheck`
 * closure. A failure returns false, which drives the runner's guaranteed rollback
 * (restore/rollback + the pre-restore safety backup backstop) — so this replaces the
 * previous `null` that made post_restore_validation pass trivially.
 *
 * It asserts TWO things about the freshly-restored site, both over real HTTP:
 *
 *   1. The site RESPONDS — a GET of the site root returns a non-error status
 *      (2xx/3xx). A 5xx (or a transport failure) means the restore left the site
 *      broken → not healthy.
 *   2. The database is PLAUSIBLE — an HMAC-signed GET of the connector's
 *      `simplead/v1/database-health` endpoint (signed with the site's own connector
 *      credentials, the same scheme WordPressHttpClient uses) is parsed for the key
 *      WordPress core tables. Each expected table must EXIST and the total row count
 *      across them must be > 0. An empty/missing core table set (a DB restore that
 *      produced nothing) → not healthy.
 *
 * When the restore scope excludes the database (files-only), the DB probe is skipped
 * and only reachability is required.
 */
final class RestoreHealthCheck
{
    /** Core WP tables (without the site table prefix) that a live site must have with rows. */
    private const CORE_TABLES = ['posts', 'options', 'users'];

    private readonly BackupLogger $logger;

    public function __construct(
        private readonly Site $site,
        private readonly HttpFactory $http,
        ?BackupLogger $logger = null,
        private readonly int $timeout = 30,
    ) {
        $this->logger = ($logger ?? new BackupLogger)->forSession('restore-health', null, $this->site->id);
    }

    public function __invoke(RestoreSession $session, RestorePlan $plan): bool
    {
        if (! $this->siteResponds()) {
            return false;
        }

        if (! $plan->includeDatabase) {
            return true; // files-only restore: reachability is the whole check
        }

        return $this->databaseHealthy($plan);
    }

    /**
     * The site root responds with a non-error status (2xx/3xx). A 5xx or a transport
     * failure means the restored site is broken.
     */
    private function siteResponds(): bool
    {
        try {
            $response = $this->http
                ->timeout($this->timeout)
                ->connectTimeout(15)
                ->get(rtrim($this->site->url, '/').'/');
        } catch (Throwable $e) {
            $this->logger->warning('health-check: site root unreachable', ['error' => $e->getMessage()]);

            return false;
        }

        // 2xx (renders) or 3xx (redirects to canonical URL) is healthy; a 4xx/5xx
        // means the restored site is broken.
        if (! ($response->successful() || $response->redirect())) {
            $this->logger->warning('health-check: site root returned error status', ['status' => $response->status()]);

            return false;
        }

        return true;
    }

    /**
     * The restored DB has the expected core tables and a plausible (> 0) total row
     * count across them.
     */
    private function databaseHealthy(RestorePlan $plan): bool
    {
        $health = $this->fetchDatabaseHealth();
        if ($health === null) {
            return false;
        }

        $rowsByTable = [];
        foreach ((array) ($health['tables'] ?? []) as $table) {
            if (is_array($table) && isset($table['name'])) {
                $rowsByTable[(string) $table['name']] = (int) ($table['rows'] ?? 0);
            }
        }

        // Selective DB restore (explicit table whitelist) verifies exactly those
        // tables; otherwise the WP core tables (prefix + posts/options/users).
        $expected = $plan->dbTables !== []
            ? $plan->dbTables
            : $this->coreTableNames((string) ($health['table_prefix'] ?? 'wp_'));

        $totalRows = 0;
        foreach ($expected as $name) {
            if (! array_key_exists($name, $rowsByTable)) {
                $this->logger->warning('health-check: expected table missing after restore', ['table' => $name]);

                return false;
            }
            $totalRows += $rowsByTable[$name];
        }

        if ($totalRows <= 0) {
            $this->logger->warning('health-check: expected tables have zero rows after restore', [
                'expected' => $expected,
            ]);

            return false;
        }

        return true;
    }

    /**
     * HMAC-signed GET of the connector's database-health endpoint. Returns the
     * decoded body, or null on any transport/auth/parse failure.
     *
     * @return array<string, mixed>|null
     */
    private function fetchDatabaseHealth(): ?array
    {
        $path = '/simplead/v1/database-health';
        $base = $this->site->api_endpoint ?: rtrim($this->site->url, '/').'/wp-json/simplead/v1';
        $url = rtrim($base, '/').'/database-health';

        try {
            $response = $this->http
                ->withHeaders($this->connectorAuthHeaders('GET', $path))
                ->timeout($this->timeout)
                ->connectTimeout(15)
                ->get($url);
        } catch (Throwable $e) {
            $this->logger->warning('health-check: database-health transport error', ['error' => $e->getMessage()]);

            return null;
        }

        if ($response->failed()) {
            $this->logger->warning('health-check: database-health returned error', ['status' => $response->status()]);

            return null;
        }

        $body = $response->json();
        if (! is_array($body)) {
            return null;
        }

        // The connector wraps successful payloads in { success/data } or returns the
        // fields at the top level depending on version — accept both.
        if (isset($body['data']) && is_array($body['data'])) {
            return $body['data'];
        }

        return $body;
    }

    /**
     * Connector HMAC headers — identical scheme to WordPressHttpClient::buildAuthHeaders
     * (string_to_sign = METHOD|path|timestamp|nonce|body). Signed with the site's
     * connector credentials, which the `encrypted` cast has already decrypted on read.
     *
     * @return array<string, string>
     */
    private function connectorAuthHeaders(string $method, string $path, string $body = ''): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $stringToSign = implode('|', [strtoupper($method), $path, $timestamp, $nonce, $body]);
        $signature = hash_hmac('sha256', $stringToSign, (string) $this->site->api_secret);

        return [
            'X-SAM-Key' => (string) $this->site->api_key,
            'X-SAM-Timestamp' => $timestamp,
            'X-SAM-Nonce' => $nonce,
            'X-SAM-Signature' => $signature,
            'User-Agent' => 'SimpleAD-Manager/2.0',
        ];
    }

    /**
     * @return list<string>
     */
    private function coreTableNames(string $prefix): array
    {
        return array_map(static fn (string $t): string => $prefix.$t, self::CORE_TABLES);
    }
}
