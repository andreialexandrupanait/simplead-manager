<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Exceptions\SsrfException;

/**
 * Server-Side Request Forgery guard.
 *
 * Validates a user / managed-site supplied URL BEFORE the manager fetches it,
 * rejecting anything that could reach the internal network, cloud metadata,
 * loopback, or a Docker service. Scope: the three outbound surfaces that fetch
 * user-controlled URLs — custom notification webhooks, the SEO quick-audit
 * crawl, and uptime monitor URLs. It is NOT used for the signed connector
 * client (managed WordPress sites live on public client domains).
 *
 * Legitimate public URLs pass: the host is resolved to its IP(s) and every
 * resolved IP must be a public, non-reserved address.
 *
 * The DNS lookup is isolated in {@see resolveIps()} so tests can subclass this
 * guard and return deterministic IPs without touching the network.
 */
class SsrfGuard
{
    /** @var list<string> */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    public function assertPublicUrl(string $url): void
    {
        $url = trim($url);

        if ($url === '') {
            throw new SsrfException('Empty URL is not allowed.');
        }

        $parts = parse_url($url);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            throw new SsrfException('Malformed URL.');
        }

        $scheme = strtolower($parts['scheme']);
        if (! in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new SsrfException("Scheme '{$scheme}' is not allowed.");
        }

        $host = strtolower(trim($parts['host'], '[]'));
        if ($host === '') {
            throw new SsrfException('URL has no host.');
        }

        // Explicit allowlist escape hatch — bypasses all further checks.
        if (in_array($host, $this->allowedHosts(), true)) {
            return;
        }

        // Reject known-internal hostnames outright (Docker service names,
        // localhost) before any DNS work — these must never be fetched.
        if (in_array($host, $this->blockedHosts(), true)) {
            throw new SsrfException("Host '{$host}' is not allowed.");
        }

        $ips = $this->resolveIps($host);

        if ($ips === []) {
            throw new SsrfException("Host '{$host}' could not be resolved to a public address.");
        }

        foreach ($ips as $ip) {
            if (! $this->isPublicIp($ip)) {
                throw new SsrfException("Host '{$host}' resolves to a non-public address ({$ip}).");
            }
        }
    }

    /**
     * True only for a valid, publicly-routable IP. Private (RFC1918, fc00::/7),
     * loopback (127/8, ::1), link-local (169.254/16, fe80::/10, incl. cloud
     * metadata 169.254.169.254) and reserved ranges are rejected.
     */
    public function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    /**
     * Resolve a hostname to its IP addresses (IPv4 + IPv6). A literal IP is
     * returned as-is. Overridable so tests stay hermetic.
     *
     * @return list<string>
     */
    protected function resolveIps(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $ips = [];

        $v4 = @gethostbynamel($host);
        if (is_array($v4)) {
            $ips = array_merge($ips, $v4);
        }

        $v6 = @dns_get_record($host, DNS_AAAA);
        if (is_array($v6)) {
            foreach ($v6 as $record) {
                if (isset($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }

    /**
     * @return list<string>
     */
    private function allowedHosts(): array
    {
        /** @var list<string> $hosts */
        $hosts = config('security.ssrf_allowed_hosts', []);

        return array_map('strtolower', $hosts);
    }

    /**
     * @return list<string>
     */
    private function blockedHosts(): array
    {
        /** @var list<string> $hosts */
        $hosts = config('security.ssrf_blocked_hosts', []);

        return array_map('strtolower', $hosts);
    }
}
