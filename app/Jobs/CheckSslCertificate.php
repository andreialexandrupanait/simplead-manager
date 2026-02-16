<?php

namespace App\Jobs;

use App\Models\SslCertificate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckSslCertificate implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 60;
    public array $backoff = [30, 60, 120];

    public function __construct(
        public SslCertificate $certificate
    ) {}

    public function uniqueId(): string
    {
        return 'ssl-check-' . $this->certificate->id;
    }

    public function handle(): void
    {
        $domain = $this->certificate->domain;
        $startTime = microtime(true);

        try {
            $info = $this->fetchSslInfo($domain);
            $handshakeTime = (int) round((microtime(true) - $startTime) * 1000);

            $expiresAt = $info['expires_at'] ? \Carbon\Carbon::parse($info['expires_at']) : null;
            $issuedAt = $info['issued_at'] ? \Carbon\Carbon::parse($info['issued_at']) : null;
            $daysRemaining = $expiresAt ? (int) now()->diffInDays($expiresAt, false) : null;

            $status = 'valid';
            if ($daysRemaining !== null && $daysRemaining < 0) {
                $status = 'expired';
            } elseif ($daysRemaining !== null && $daysRemaining <= $this->certificate->warn_days) {
                $status = 'expiring_soon';
            }

            $this->certificate->update([
                'issuer' => $info['issuer'],
                'issuer_organisation' => $info['issuer_org'],
                'san_domains' => $info['san_domains'],
                'signature_algorithm' => $info['signature_algorithm'],
                'key_size' => $info['key_size'],
                'protocol' => $info['protocol'],
                'cipher' => $info['cipher'],
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
                'days_remaining' => $daysRemaining,
                'chain_valid' => $info['chain_valid'],
                'status' => $status,
                'error_message' => null,
                'handshake_time' => $handshakeTime,
                'last_checked_at' => now(),
                'next_check_at' => now()->addHours(12),
            ]);

            $this->certificate->history()->create([
                'status' => $status,
                'days_remaining' => $daysRemaining,
                'issuer' => $info['issuer'],
                'protocol' => $info['protocol'],
                'cipher' => $info['cipher'],
                'chain_valid' => $info['chain_valid'],
                'handshake_time' => $handshakeTime,
                'checked_at' => now(),
            ]);

            $this->checkAlerts($status);
        } catch (\Exception $e) {
            $handshakeTime = (int) round((microtime(true) - $startTime) * 1000);

            $this->certificate->update([
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'handshake_time' => $handshakeTime,
                'last_checked_at' => now(),
                'next_check_at' => now()->addHours(1),
            ]);

            $this->certificate->history()->create([
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'handshake_time' => $handshakeTime,
                'checked_at' => now(),
            ]);

            $this->checkAlerts('error');
        }
    }

    protected function fetchSslInfo(string $domain): array
    {
        $context = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'capture_peer_cert_chain' => true,
                'verify_peer' => true,
                'SNI_enabled' => true,
                'peer_name' => $domain,
            ],
        ]);

        $stream = @stream_socket_client(
            "ssl://{$domain}:443",
            $errno,
            $errstr,
            30,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$stream) {
            throw new \RuntimeException("SSL connection failed: {$errstr} (errno: {$errno})");
        }

        $params = stream_context_get_params($stream);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        $chain = $params['options']['ssl']['peer_certificate_chain'] ?? [];

        if (!$cert) {
            fclose($stream);
            throw new \RuntimeException("No peer certificate received");
        }

        $certData = openssl_x509_parse($cert);
        if (!$certData) {
            fclose($stream);
            throw new \RuntimeException("Failed to parse SSL certificate");
        }

        // Extract issuer
        $issuer = $certData['issuer']['CN'] ?? $certData['issuer']['O'] ?? 'Unknown';
        $issuerOrg = $certData['issuer']['O'] ?? null;

        // Extract SAN domains
        $sanDomains = [];
        if (!empty($certData['extensions']['subjectAltName'])) {
            $sans = explode(',', $certData['extensions']['subjectAltName']);
            foreach ($sans as $san) {
                $san = trim($san);
                if (str_starts_with($san, 'DNS:')) {
                    $sanDomains[] = substr($san, 4);
                }
            }
        }

        // Signature algorithm
        $signatureAlgorithm = $certData['signatureTypeSN'] ?? $certData['signatureTypeLN'] ?? null;

        // Key size
        $keySize = null;
        $pubKey = openssl_pkey_get_public($cert);
        if ($pubKey) {
            $keyDetails = openssl_pkey_get_details($pubKey);
            $keySize = $keyDetails['bits'] ?? null;
        }

        // Protocol and cipher from stream metadata
        $meta = stream_get_meta_data($stream);
        $protocol = $meta['crypto']['protocol'] ?? null;
        $cipher = $meta['crypto']['cipher_name'] ?? null;

        // Chain validation
        $chainValid = count($chain) >= 2;

        // Dates
        $issuedAt = isset($certData['validFrom_time_t'])
            ? date('Y-m-d H:i:s', $certData['validFrom_time_t'])
            : null;
        $expiresAt = isset($certData['validTo_time_t'])
            ? date('Y-m-d H:i:s', $certData['validTo_time_t'])
            : null;

        fclose($stream);

        return [
            'issuer' => $issuer,
            'issuer_org' => $issuerOrg,
            'san_domains' => $sanDomains,
            'signature_algorithm' => $signatureAlgorithm,
            'key_size' => $keySize,
            'protocol' => $protocol,
            'cipher' => $cipher,
            'chain_valid' => $chainValid,
            'issued_at' => $issuedAt,
            'expires_at' => $expiresAt,
        ];
    }

    protected function checkAlerts(string $status): void
    {
        if (!$this->certificate->alerts_enabled) {
            return;
        }

        if (!in_array($status, ['expired', 'expiring_soon', 'error'])) {
            return;
        }

        // Rate limit expiring_soon alerts to once per day
        if ($status === 'expiring_soon' && $this->certificate->last_alert_sent_at) {
            if ($this->certificate->last_alert_sent_at->isToday()) {
                return;
            }
        }

        NotifySslAlert::dispatch($this->certificate, $status);

        $this->certificate->update(['last_alert_sent_at' => now()]);
    }
}
