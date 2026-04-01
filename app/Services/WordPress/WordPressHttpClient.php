<?php

declare(strict_types=1);

namespace App\Services\WordPress;

use App\Exceptions\WordPressApiException;
use App\Models\Site;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Low-level HTTP client for WordPress connector API.
 * Handles HMAC signing, throttling, retry logic, and curl-based streaming.
 */
class WordPressHttpClient
{
    private float $lastRequestTime = 0;

    private float $minRequestInterval = 1.5;

    private float $baseRequestInterval = 1.5;

    public function __construct(
        protected Site $site,
    ) {}

    // ── Throttle management ─────────────────────────────────────────

    public function setBackupMode(bool $enabled): void
    {
        $this->baseRequestInterval = $enabled ? 0.5 : 1.5;
        $this->minRequestInterval = $this->baseRequestInterval;
    }

    public function resetThrottle(): void
    {
        $this->minRequestInterval = $this->baseRequestInterval;
    }

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

    private function backoffThrottle(): void
    {
        $this->minRequestInterval = min($this->minRequestInterval * 2, 15.0);
        Log::info("Throttle increased to {$this->minRequestInterval}s after rate limit");
    }

    private function relaxThrottle(): void
    {
        if ($this->minRequestInterval > $this->baseRequestInterval) {
            $this->minRequestInterval = max($this->minRequestInterval * 0.8, $this->baseRequestInterval);
        }
    }

    // ── URL and auth ────────────────────────────────────────────────

    /**
     * @return array{0: string, 1: string} [$url, $path]
     */
    public function buildUrl(string $endpoint): array
    {
        $baseUrl = $this->site->api_endpoint ?: rtrim($this->site->url, '/').'/wp-json/simplead/v1';
        $url = rtrim($baseUrl, '/').'/'.ltrim($endpoint, '/');
        $path = '/simplead/v1/'.ltrim($endpoint, '/');

        return [$url, $path];
    }

    public function buildAuthHeaders(string $method, string $path, string $body = ''): array
    {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));

        $stringToSign = implode('|', [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            $body,
        ]);

        $signature = hash_hmac('sha256', $stringToSign, (string) $this->site->api_secret);

        return [
            'X-SAM-Key' => (string) $this->site->api_key,
            'X-SAM-Timestamp' => $timestamp,
            'X-SAM-Nonce' => $nonce,
            'X-SAM-Signature' => $signature,
            'User-Agent' => 'SimpleAD-Manager/2.0',
        ];
    }

    public function formatCurlHeaders(array $headers): array
    {
        return array_map(
            fn (string $key, string $value) => "{$key}: {$value}",
            array_keys($headers),
            array_values($headers),
        );
    }

    // ── HTTP execution with retry ───────────────────────────────────

    public function httpRequestWithRetry(string $method, string $url, string $path, string $body, array $extraHeaders, int $timeout, string $logLabel): Response
    {
        $maxRetries = 5;
        $response = null;

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $this->throttle();

            $headers = array_merge($this->buildAuthHeaders($method, $path, $body), $extraHeaders);

            $request = Http::withHeaders($headers)->timeout($timeout)->connectTimeout(30);

            if (strtoupper($method) === 'GET') {
                $response = $request->get($url);
            } else {
                $response = $request->withBody($body, 'application/json')->post($url);
            }

            if ($response->status() === 429 && $attempt < $maxRetries) {
                $retryAfter = (int) $response->header('Retry-After') ?: min(10 * pow(2, $attempt), 120);
                $retryAfter = min(max($retryAfter, 5), 120);
                Log::warning("Rate limited (429) on {$logLabel}, retry ".($attempt + 1)."/{$maxRetries} after {$retryAfter}s");
                $this->backoffThrottle();
                sleep($retryAfter);

                continue;
            }

            $this->relaxThrottle();
            break;
        }

        return $response;
    }

    public function curlDownloadWithRetry(string $url, string $path, string $body, string $saveTo, int $timeout = 600, int $maxRetries = 5): void
    {
        $success = false;
        $httpCode = 0;
        $error = '';

        for ($attempt = 0; $attempt <= $maxRetries; $attempt++) {
            $this->throttle();

            $headers = $this->buildAuthHeaders('POST', $path, $body);
            $headers['Content-Type'] = 'application/json';

            $fh = fopen($saveTo, 'wb');
            if (! $fh) {
                throw new \RuntimeException("Cannot open {$saveTo} for writing");
            }

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_FILE => $fh,
                CURLOPT_HTTPHEADER => $this->formatCurlHeaders($headers),
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_CONNECTTIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);

            $success = curl_exec($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);
            fclose($fh);

            if ($httpCode === 429 && $attempt < $maxRetries) {
                $retryAfter = min(10 * pow(2, $attempt), 120);
                Log::warning('Rate limited (429) on stream download, retry '.($attempt + 1)."/{$maxRetries} after {$retryAfter}s");
                $this->backoffThrottle();
                @unlink($saveTo);
                sleep($retryAfter);

                continue;
            }

            $this->relaxThrottle();
            break;
        }

        if (! $success || $httpCode >= 400) {
            $efh = fopen($saveTo, 'rb');
            $errorBody = $efh ? fread($efh, 1024) : '';
            if ($efh) {
                fclose($efh);
            }
            @unlink($saveTo);
            throw new \RuntimeException("Stream download failed (HTTP {$httpCode}): ".($error ?: substr($errorBody, 0, 500)));
        }

        clearstatcache(true, $saveTo);
        if (filesize($saveTo) === 0) {
            @unlink($saveTo);
            throw new \RuntimeException("Stream download returned empty file (HTTP {$httpCode})");
        }
    }

    // ── High-level request methods ──────────────────────────────────

    public function request(string $method, string $endpoint, array $data = [], array $queryParams = [], int $timeout = 30): Response
    {
        [$url, $path] = $this->buildUrl($endpoint);

        $queryParams['_nocache'] = time();
        $url .= '?'.http_build_query($queryParams);
        $body = ! empty($data) ? json_encode($data) : '';

        $response = $this->httpRequestWithRetry($method, $url, $path, $body, ['Accept' => 'application/json'], $timeout, $endpoint);

        if ($response->status() === 403 && str_contains($response->body(), 'Just a moment')) {
            throw new WordPressApiException(
                'Cloudflare is blocking API requests to this site. '
                .'Add a WAF exception rule in Cloudflare for the path /wp-json/simplead/v1/* '
                .'or whitelist this server\'s IP address.',
                site: $this->site,
                endpoint: $endpoint,
                httpStatus: 403,
            );
        }

        return $response;
    }

    public function requestRaw(string $method, string $endpoint, array $data = [], int $timeout = 30): Response
    {
        [$url, $path] = $this->buildUrl($endpoint);
        $body = ! empty($data) ? json_encode($data) : '';

        $response = $this->httpRequestWithRetry($method, $url, $path, $body, [], $timeout, "raw {$endpoint}");

        $response->throw();

        return $response;
    }

    public function streamDownloadTo(string $endpoint, array $data, string $saveTo, int $maxRetries = 5): void
    {
        [$url, $path] = $this->buildUrl($endpoint);
        $body = ! empty($data) ? json_encode($data) : '';

        $this->curlDownloadWithRetry($url, $path, $body, $saveTo, 600, $maxRetries);
    }

    public function streamDownload(string $endpoint, string $saveTo): void
    {
        [$url, $path] = $this->buildUrl($endpoint);

        $dir = dirname($saveTo);
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->curlDownloadWithRetry($url, $path, '', $saveTo, 1800);
    }

    public function throwIfFailed(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $json = $response->json();
        $message = $json['error']['message']
            ?? $json['message']
            ?? null;

        if ($message) {
            throw new WordPressApiException($message, site: $this->site, httpStatus: $response->status());
        }

        $response->throw();
    }
}
