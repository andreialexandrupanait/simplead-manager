<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EmailHealthCheck;
use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;

class EmailDeliverabilityService
{
    protected static array $dkimSelectors = [
        'default', 'google', 'selector1', 'selector2', 'k1', 'mail', 'dkim', 's1', 's2',
    ];

    protected static array $blacklists = [
        'zen.spamhaus.org',
        'bl.spamcop.net',
        'b.barracudacentral.org',
        'dnsbl.sorbs.net',
        'dnsbl-1.uceprotect.net',
    ];

    public static function check(Site $site): EmailHealthCheck
    {
        $domain = parse_url($site->url, PHP_URL_HOST) ?? $site->url;
        $domain = preg_replace('/^www\./', '', $domain);

        // SPF check
        [$spfExists, $spfRecord, $spfStatus, $spfIssues] = static::checkSpf($domain);

        // DMARC check
        [$dmarcExists, $dmarcRecord, $dmarcPolicy, $dmarcStatus] = static::checkDmarc($domain);

        // DKIM check
        [$dkimExists, $dkimSelector, $dkimStatus] = static::checkDkim($domain);

        // Blacklist check
        $ips = static::getIps($domain);
        [$blacklistsChecked, $blacklistsClean, $blacklistsListed] = static::checkBlacklists($ips);

        // MX records
        $mxRecords = static::getMxRecords($domain);

        // Score calculation
        $score = 0;
        if ($spfStatus === 'valid') {
            $score += 33;
        }
        if ($dmarcStatus === 'valid') {
            $score += 34;
        }
        if ($dkimExists) {
            $score += 33;
        }
        $score -= ($blacklistsListed * 20);
        $score = max(0, min(100, $score));

        // Status
        $status = match (true) {
            $score >= 90 => 'excellent',
            $score >= 70 => 'good',
            $score >= 50 => 'warning',
            default => 'critical',
        };

        $healthCheck = EmailHealthCheck::create([
            'site_id' => $site->id,
            'domain' => $domain,
            'spf_exists' => $spfExists,
            'spf_record' => $spfRecord,
            'spf_status' => $spfStatus,
            'spf_issues' => $spfIssues,
            'dkim_exists' => $dkimExists,
            'dkim_selector' => $dkimSelector,
            'dkim_status' => $dkimStatus,
            'dmarc_exists' => $dmarcExists,
            'dmarc_record' => $dmarcRecord,
            'dmarc_policy' => $dmarcPolicy,
            'dmarc_status' => $dmarcStatus,
            'blacklists_checked' => $blacklistsChecked,
            'blacklists_clean' => $blacklistsClean,
            'blacklists_listed' => $blacklistsListed,
            'mx_records' => $mxRecords,
            'score' => $score,
            'status' => $status,
            'checked_at' => now(),
        ]);

        // Notify if blacklisted
        if ($blacklistsListed > 0) {
            $listedNames = collect($blacklistsChecked)
                ->filter(fn ($bl) => $bl['listed'])
                ->pluck('name')
                ->implode(', ');

            NotificationService::notifySiteEvent(
                site: $site,
                event: 'email_blacklisted',
                title: "Email blacklist detected for {$site->name}",
                message: "Mail server IP listed on: {$listedNames}",
                fields: [
                    'Domain' => $domain,
                    'Blacklists' => $listedNames,
                    'Score' => "{$score}/100",
                ],
                severity: 'warning',
            );
        }

        ActivityLogger::log(
            type: 'email',
            severity: $status === 'excellent' || $status === 'good' ? 'info' : 'warning',
            title: "Email deliverability check for {$site->name}",
            description: "Score: {$score}/100, Status: {$status}",
            site: $site,
            icon: 'mail',
            url: route('sites.overview', $site),
        );

        return $healthCheck;
    }

    protected static function checkSpf(string $domain): array
    {
        try {
            $records = dns_get_record($domain, DNS_TXT);
            if (! $records) {
                return [false, null, 'missing', []];
            }

            foreach ($records as $record) {
                $value = $record['txt'] ?? '';
                if (str_starts_with(strtolower($value), 'v=spf1')) {
                    $issues = [];

                    // Basic validation
                    if (str_contains($value, '+all')) {
                        $issues[] = 'SPF record uses "+all" which allows any server to send email';
                    }

                    $status = empty($issues) ? 'valid' : 'invalid';

                    return [true, $value, $status, $issues];
                }
            }
        } catch (\Exception $e) {
            Log::warning("SPF check failed for {$domain}: {$e->getMessage()}");
        }

        return [false, null, 'missing', []];
    }

    protected static function checkDmarc(string $domain): array
    {
        try {
            $records = dns_get_record("_dmarc.{$domain}", DNS_TXT);
            if (! $records) {
                return [false, null, null, 'missing'];
            }

            foreach ($records as $record) {
                $value = $record['txt'] ?? '';
                if (str_contains(strtolower($value), 'v=dmarc1')) {
                    // Extract policy
                    $policy = null;
                    if (preg_match('/p=(none|quarantine|reject)/i', $value, $matches)) {
                        $policy = strtolower($matches[1]);
                    }

                    return [true, $value, $policy, 'valid'];
                }
            }
        } catch (\Exception $e) {
            Log::warning("DMARC check failed for {$domain}: {$e->getMessage()}");
        }

        return [false, null, null, 'missing'];
    }

    protected static function checkDkim(string $domain): array
    {
        foreach (static::$dkimSelectors as $selector) {
            try {
                $records = dns_get_record("{$selector}._domainkey.{$domain}", DNS_TXT);
                if (! empty($records)) {
                    foreach ($records as $record) {
                        $value = strtolower($record['txt'] ?? '');
                        if (str_contains($value, 'v=dkim1') || str_contains($value, 'k=rsa')) {
                            return [true, $selector, 'valid'];
                        }
                    }
                }
            } catch (\Exception) {
                continue;
            }
        }

        return [false, null, 'missing'];
    }

    protected static function getIps(string $domain): array
    {
        try {
            $records = dns_get_record($domain, DNS_A);

            return collect($records)->pluck('ip')->filter()->values()->all();
        } catch (\Exception) {
            return [];
        }
    }

    protected static function checkBlacklists(array $ips): array
    {
        $checked = [];
        $clean = 0;
        $listed = 0;

        foreach ($ips as $ip) {
            $reversed = implode('.', array_reverse(explode('.', $ip)));

            foreach (static::$blacklists as $blacklist) {
                $lookup = "{$reversed}.{$blacklist}";
                $isListed = false;

                try {
                    $result = dns_get_record($lookup, DNS_A);
                    $isListed = ! empty($result);
                } catch (\Exception) {
                    // Treat lookup failure as not listed
                }

                $checked[] = [
                    'name' => $blacklist,
                    'listed' => $isListed,
                    'ip' => $ip,
                ];

                if ($isListed) {
                    $listed++;
                } else {
                    $clean++;
                }
            }
        }

        return [$checked, $clean, $listed];
    }

    protected static function getMxRecords(string $domain): array
    {
        try {
            $records = dns_get_record($domain, DNS_MX);

            return collect($records)->map(fn ($r) => [
                'priority' => $r['pri'] ?? 0,
                'host' => $r['target'] ?? '',
            ])->sortBy('priority')->values()->all();
        } catch (\Exception) {
            return [];
        }
    }
}
