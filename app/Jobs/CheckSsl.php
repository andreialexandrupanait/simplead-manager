<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\UptimeMonitor;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * P2-08: fetches the TLS certificate expiry for an uptime monitor's host and
 * stores it, so near-expiry can be surfaced as an action item / notification.
 *
 * The plumbing (`check_ssl`, `ssl_expiry_threshold`, `uptime_checks.ssl_expires_at`)
 * existed but nothing populated it. This job connects the dead ends: it opens a
 * plain TLS connection to port 443, reads the peer certificate and parses its
 * `validTo` — never a blocking synchronous check in a web request, always queued
 * on the monitor cadence, mirroring CheckUptime / CheckDns.
 */
class CheckSsl implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 90;

    public function __construct(
        public UptimeMonitor $monitor,
    ) {
        $this->onQueue('uptime');
    }

    public function uniqueId(): string
    {
        return 'check-ssl-'.$this->monitor->id;
    }

    public function handle(): void
    {
        $next = now()->addHours((int) config('monitoring.ssl_check_interval_hours', 24));

        $host = $this->hostFromUrl();

        // Only https endpoints have a certificate to check. Advance the cadence
        // so a non-https monitor isn't re-selected every minute.
        if ($host === null) {
            $this->monitor->update([
                'ssl_last_checked_at' => now(),
                'next_ssl_check_at' => $next,
                'ssl_last_error' => 'Not an HTTPS endpoint',
            ]);

            return;
        }

        try {
            $cert = $this->fetchCertificate($host, 443);

            $expiresAt = $this->parseExpiry($cert);

            if ($expiresAt === null) {
                throw new \RuntimeException('Certificate did not contain a valid expiry date.');
            }

            $this->monitor->update([
                'ssl_expires_at' => $expiresAt,
                'ssl_issuer' => $this->parseIssuer($cert),
                'ssl_last_checked_at' => now(),
                'ssl_last_error' => null,
                'next_ssl_check_at' => $next,
            ]);

            $this->maybeNotify($expiresAt);
        } catch (\Throwable $e) {
            Log::warning("SSL check failed for {$host}: {$e->getMessage()}");

            // Preserve the last-known expiry (mirrors the DNS / RDAP carry-forward
            // pattern) — a transient TLS blip must not erase a real near-expiry
            // warning. Record only the error and advance the cadence.
            $this->monitor->update([
                'ssl_last_checked_at' => now(),
                'ssl_last_error' => Str::limit($e->getMessage(), 200),
                'next_ssl_check_at' => $next,
            ]);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        try {
            $this->monitor->update([
                'ssl_last_checked_at' => now(),
                'ssl_last_error' => Str::limit(
                    $exception?->getMessage() ?? 'SSL check aborted (timeout or worker killed)',
                    200
                ),
                'next_ssl_check_at' => now()->addHours((int) config('monitoring.ssl_check_interval_hours', 24)),
            ]);
        } catch (\Throwable $e) {
            Log::error('Failed to record SSL check failure for monitor '.$this->monitor->id.': '.$e->getMessage());
        }
    }

    /**
     * Extract the host from the monitor URL, or null when it is not https.
     */
    private function hostFromUrl(): ?string
    {
        $parts = parse_url($this->monitor->url);

        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https') {
            return null;
        }

        $host = $parts['host'] ?? null;

        return is_string($host) && $host !== '' ? $host : null;
    }

    /**
     * Open a TLS connection and return the parsed peer certificate.
     *
     * This is the cert-fetch seam — tests override it to inject a parsed cert so
     * they stay hermetic (no live network). These are public client domains, so
     * a plain TLS connect to port 443 is fine (no SSRF concern for the host).
     *
     * @return array<string, mixed>
     */
    protected function fetchCertificate(string $host, int $port): array
    {
        $timeout = (int) config('monitoring.ssl_connect_timeout_seconds', 10);

        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'verify_peer' => false,
                'verify_peer_name' => false,
                'SNI_enabled' => true,
                'peer_name' => $host,
            ],
        ]);

        $client = @stream_socket_client(
            'ssl://'.$host.':'.$port,
            $errno,
            $errstr,
            $timeout,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if ($client === false) {
            throw new \RuntimeException("TLS connection failed: {$errstr} ({$errno})");
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $peerCert = $params['options']['ssl']['peer_certificate'] ?? null;

        if ($peerCert === null) {
            throw new \RuntimeException('No peer certificate presented.');
        }

        $parsed = openssl_x509_parse($peerCert);

        if ($parsed === false) {
            throw new \RuntimeException('Certificate could not be parsed.');
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $cert
     */
    private function parseExpiry(array $cert): ?Carbon
    {
        $validTo = $cert['validTo_time_t'] ?? null;

        if (! is_int($validTo) && ! (is_string($validTo) && ctype_digit($validTo))) {
            return null;
        }

        // Use the app timezone (not forced UTC) so the stored wall-clock aligns
        // with every other Carbon the app persists — otherwise ssl_expires_at is
        // written as a UTC wall-clock but read back through the app-tz cast,
        // shifting the date by up to a day near midnight (flaky, tz-dependent).
        return Carbon::createFromTimestamp((int) $validTo);
    }

    /**
     * @param  array<string, mixed>  $cert
     */
    private function parseIssuer(array $cert): ?string
    {
        $issuer = $cert['issuer'] ?? null;

        if (! is_array($issuer)) {
            return null;
        }

        $name = $issuer['O'] ?? $issuer['CN'] ?? null;

        return is_string($name) ? Str::limit($name, 255, '') : null;
    }

    private function maybeNotify(Carbon $expiresAt): void
    {
        $fresh = $this->monitor->fresh() ?? $this->monitor;

        if (! $fresh->sslIsExpired() && ! $fresh->sslIsExpiringSoon()) {
            return;
        }

        /** @var Site|null $site */
        $site = $fresh->site;

        if ($site === null) {
            return;
        }

        $when = $expiresAt->isPast()
            ? 'expired '.$expiresAt->diffForHumans()
            : 'expires '.$expiresAt->diffForHumans();

        NotificationService::notifySiteEventSlim(
            site: $site,
            event: 'ssl_expiring',
            summary: "\xF0\x9F\x94\x92 SSL · *{$site->name}* — the TLS certificate {$when}.",
            deepLink: '<'.route('sites.overview', $site).'|Open site →>',
            severity: $expiresAt->isPast() ? 'critical' : 'warning',
        );
    }
}
