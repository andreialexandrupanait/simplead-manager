<?php

namespace App\Services;

use App\Models\DnsRecordCache;
use App\Models\Site;
use Illuminate\Support\Facades\Log;

class DnsService
{
    protected static array $dkimSelectors = [
        'default', 'google', 'selector1', 'selector2', 'k1', 'mail', 'dkim', 's1', 's2',
    ];

    protected static array $mailProviders = [
        'google.com' => 'Google Workspace',
        'googlemail.com' => 'Google Workspace',
        'outlook.com' => 'Microsoft 365',
        'protection.outlook.com' => 'Microsoft 365',
        'pphosted.com' => 'Proofpoint',
        'zoho.com' => 'Zoho Mail',
        'zoho.eu' => 'Zoho Mail',
        'protonmail.ch' => 'Proton Mail',
        'registrar-servers.com' => 'Namecheap',
        'mxrouting.net' => 'MXroute',
        'secureserver.net' => 'GoDaddy',
        'emailsrvr.com' => 'Rackspace',
        'messagingengine.com' => 'Fastmail',
        'migadu.com' => 'Migadu',
        'improvmx.com' => 'ImprovMX',
        'forwardemail.net' => 'Forward Email',
        'icloud.com' => 'iCloud Mail',
        'yahoodns.net' => 'Yahoo Mail',
        'privateemail.com' => 'Namecheap Email',
    ];

    public static function lookup(string $domain): array
    {
        $records = [];

        $records['a'] = static::fetchRecords($domain, DNS_A);
        $records['aaaa'] = static::fetchRecords($domain, DNS_AAAA);
        $records['cname'] = static::fetchRecords($domain, DNS_CNAME);
        $records['mx'] = static::fetchRecords($domain, DNS_MX);
        $records['txt'] = static::fetchRecords($domain, DNS_TXT);
        $records['ns'] = static::fetchRecords($domain, DNS_NS);
        $records['soa'] = static::fetchRecords($domain, DNS_SOA);

        return $records;
    }

    public static function fetchAndCache(Site $site): DnsRecordCache
    {
        $domain = $site->domain;
        $records = static::lookup($domain);

        $aRecords = static::formatARecords($records['a']);
        $aaaaRecords = static::formatAaaaRecords($records['aaaa']);
        $cnameRecords = static::formatCnameRecords($records['cname']);
        $mxRecords = static::formatMxRecords($records['mx']);
        $txtRecords = static::formatTxtRecords($records['txt']);
        $nsRecords = static::formatNsRecords($records['ns']);
        $soaRecord = static::formatSoaRecord($records['soa']);

        $hasWww = static::detectWww($domain);
        $usesCloudflare = static::detectCloudflare($nsRecords);
        $hasSpf = static::detectSpf($txtRecords);
        $hasDmarc = static::detectDmarc($domain);
        $hasDkim = static::detectDkim($domain);
        $mailProvider = static::detectMailProvider($mxRecords);
        $emailSecurityScore = static::calculateEmailSecurityScore($hasSpf, $hasDmarc, $hasDkim);

        $totalRecords = count($aRecords) + count($aaaaRecords) + count($cnameRecords)
            + count($mxRecords) + count($txtRecords) + count($nsRecords)
            + (! empty($soaRecord) ? 1 : 0);

        return DnsRecordCache::updateOrCreate(
            ['site_id' => $site->id],
            [
                'domain' => $domain,
                'a_records' => $aRecords,
                'aaaa_records' => $aaaaRecords,
                'cname_records' => $cnameRecords,
                'mx_records' => $mxRecords,
                'txt_records' => $txtRecords,
                'ns_records' => $nsRecords,
                'soa_record' => $soaRecord,
                'has_www' => $hasWww,
                'uses_cloudflare' => $usesCloudflare,
                'has_spf' => $hasSpf,
                'has_dmarc' => $hasDmarc,
                'has_dkim' => $hasDkim,
                'mail_provider' => $mailProvider,
                'email_security_score' => $emailSecurityScore,
                'total_records' => $totalRecords,
                'checked_at' => now(),
            ]
        );
    }

    protected static function fetchRecords(string $domain, int $type): array
    {
        try {
            $result = dns_get_record($domain, $type);

            return $result ?: [];
        } catch (\Throwable $e) {
            Log::warning("DNS lookup failed for {$domain} (type {$type}): {$e->getMessage()}");

            return [];
        }
    }

    protected static function formatARecords(array $records): array
    {
        return collect($records)->map(fn ($r) => [
            'ip' => $r['ip'] ?? null,
            'ttl' => $r['ttl'] ?? null,
        ])->filter(fn ($r) => $r['ip'])->values()->all();
    }

    protected static function formatAaaaRecords(array $records): array
    {
        return collect($records)->map(fn ($r) => [
            'ipv6' => $r['ipv6'] ?? null,
            'ttl' => $r['ttl'] ?? null,
        ])->filter(fn ($r) => $r['ipv6'])->values()->all();
    }

    protected static function formatCnameRecords(array $records): array
    {
        return collect($records)->map(fn ($r) => [
            'target' => $r['target'] ?? null,
            'ttl' => $r['ttl'] ?? null,
        ])->filter(fn ($r) => $r['target'])->values()->all();
    }

    protected static function formatMxRecords(array $records): array
    {
        return collect($records)->map(fn ($r) => [
            'host' => $r['target'] ?? null,
            'priority' => $r['pri'] ?? null,
            'ttl' => $r['ttl'] ?? null,
        ])->filter(fn ($r) => $r['host'])->sortBy('priority')->values()->all();
    }

    protected static function formatTxtRecords(array $records): array
    {
        return collect($records)->map(fn ($r) => [
            'value' => $r['txt'] ?? null,
            'ttl' => $r['ttl'] ?? null,
        ])->filter(fn ($r) => $r['value'])->values()->all();
    }

    protected static function formatNsRecords(array $records): array
    {
        return collect($records)->map(fn ($r) => [
            'target' => $r['target'] ?? null,
            'ttl' => $r['ttl'] ?? null,
        ])->filter(fn ($r) => $r['target'])->values()->all();
    }

    protected static function formatSoaRecord(array $records): ?array
    {
        if (empty($records)) {
            return null;
        }

        $r = $records[0];

        return [
            'mname' => $r['mname'] ?? null,
            'rname' => $r['rname'] ?? null,
            'serial' => $r['serial'] ?? null,
            'refresh' => $r['refresh'] ?? null,
            'retry' => $r['retry'] ?? null,
            'expire' => $r['expire'] ?? null,
            'minimum_ttl' => $r['minimum-ttl'] ?? null,
            'ttl' => $r['ttl'] ?? null,
        ];
    }

    protected static function detectWww(string $domain): bool
    {
        $wwwDomain = 'www.' . $domain;

        try {
            $records = dns_get_record($wwwDomain, DNS_A | DNS_CNAME);

            return ! empty($records);
        } catch (\Throwable) {
            return false;
        }
    }

    protected static function detectCloudflare(array $nsRecords): bool
    {
        foreach ($nsRecords as $ns) {
            $target = strtolower($ns['target'] ?? '');
            if (str_contains($target, 'cloudflare.com')) {
                return true;
            }
        }

        return false;
    }

    protected static function detectSpf(array $txtRecords): bool
    {
        foreach ($txtRecords as $txt) {
            $value = strtolower($txt['value'] ?? '');
            if (str_starts_with($value, 'v=spf1')) {
                return true;
            }
        }

        return false;
    }

    protected static function detectDmarc(string $domain): bool
    {
        try {
            $records = dns_get_record("_dmarc.{$domain}", DNS_TXT);
            if (! $records) {
                return false;
            }

            foreach ($records as $r) {
                $value = strtolower($r['txt'] ?? '');
                if (str_contains($value, 'v=dmarc1')) {
                    return true;
                }
            }
        } catch (\Throwable) {
            // Ignore lookup failures
        }

        return false;
    }

    protected static function detectDkim(string $domain): bool
    {
        foreach (static::$dkimSelectors as $selector) {
            try {
                $records = dns_get_record("{$selector}._domainkey.{$domain}", DNS_TXT);
                if (! empty($records)) {
                    foreach ($records as $r) {
                        $value = strtolower($r['txt'] ?? '');
                        if (str_contains($value, 'v=dkim1') || str_contains($value, 'k=rsa')) {
                            return true;
                        }
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    protected static function detectMailProvider(array $mxRecords): ?string
    {
        foreach ($mxRecords as $mx) {
            $host = strtolower($mx['host'] ?? '');

            foreach (static::$mailProviders as $pattern => $provider) {
                if (str_contains($host, $pattern)) {
                    return $provider;
                }
            }
        }

        return null;
    }

    protected static function calculateEmailSecurityScore(bool $hasSpf, bool $hasDmarc, bool $hasDkim): int
    {
        $score = 0;

        if ($hasSpf) {
            $score += 34;
        }
        if ($hasDmarc) {
            $score += 33;
        }
        if ($hasDkim) {
            $score += 33;
        }

        return $score;
    }
}
