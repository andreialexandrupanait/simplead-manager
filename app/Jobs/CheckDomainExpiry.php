<?php

namespace App\Jobs;

use App\Models\DomainMonitor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckDomainExpiry implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(
        public DomainMonitor $domainMonitor
    ) {}

    public function handle(): void
    {
        $domain = $this->domainMonitor->domain;

        try {
            $info = $this->lookupWhois($domain);

            $expiresAt = $info['expires_at'] ? \Carbon\Carbon::parse($info['expires_at']) : null;
            $registeredAt = $info['registered_at'] ? \Carbon\Carbon::parse($info['registered_at']) : null;
            $updatedAt = $info['updated_at'] ? \Carbon\Carbon::parse($info['updated_at']) : null;
            $daysRemaining = $expiresAt ? (int) now()->diffInDays($expiresAt, false) : null;

            $status = 'active';
            if ($daysRemaining !== null && $daysRemaining < 0) {
                $status = 'expired';
            } elseif ($daysRemaining !== null && $daysRemaining <= $this->domainMonitor->warn_days) {
                $status = 'expiring_soon';
            }

            $dnsProvider = $this->detectDnsProvider($info['nameservers'] ?? []);

            $this->domainMonitor->update([
                'registrar' => $info['registrar'],
                'registrar_url' => $info['registrar_url'],
                'registered_at' => $registeredAt,
                'expires_at' => $expiresAt,
                'updated_at' => $updatedAt,
                'days_remaining' => $daysRemaining,
                'nameservers' => $info['nameservers'],
                'dns_provider' => $dnsProvider,
                'domain_statuses' => $info['domain_statuses'],
                'status' => $status,
                'error_message' => null,
                'last_checked_at' => now(),
                'next_check_at' => now()->addDay(),
            ]);

            $this->domainMonitor->history()->create([
                'status' => $status,
                'days_remaining' => $daysRemaining,
                'registrar' => $info['registrar'],
                'nameservers' => $info['nameservers'],
                'checked_at' => now(),
            ]);

            $this->checkAlerts($status);
        } catch (\Exception $e) {
            $this->domainMonitor->update([
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'last_checked_at' => now(),
                'next_check_at' => now()->addHours(6),
            ]);

            $this->domainMonitor->history()->create([
                'status' => 'error',
                'error_message' => $e->getMessage(),
                'checked_at' => now(),
            ]);
        }
    }

    protected function lookupWhois(string $domain): array
    {
        $output = shell_exec('whois ' . escapeshellarg($domain) . ' 2>&1');

        if (!$output) {
            throw new \RuntimeException("WHOIS lookup returned no data for {$domain}");
        }

        return [
            'registrar' => $this->extractWhoisField($output, [
                'Registrar:',
                'registrar:',
                'Sponsoring Registrar:',
            ]),
            'registrar_url' => $this->extractWhoisField($output, [
                'Registrar URL:',
                'Referral URL:',
            ]),
            'registered_at' => $this->extractWhoisField($output, [
                'Creation Date:',
                'Created Date:',
                'created:',
                'Registration Date:',
                'Domain Registration Date:',
            ]),
            'expires_at' => $this->extractWhoisField($output, [
                'Registry Expiry Date:',
                'Registrar Registration Expiration Date:',
                'Expiration Date:',
                'Expiry Date:',
                'paid-till:',
                'expire:',
                'Expires:',
                'Domain Expiration Date:',
            ]),
            'updated_at' => $this->extractWhoisField($output, [
                'Updated Date:',
                'Last Modified:',
                'last-modified:',
            ]),
            'nameservers' => $this->extractNameservers($output),
            'domain_statuses' => $this->extractDomainStatuses($output),
        ];
    }

    protected function extractWhoisField(string $output, array $labels): ?string
    {
        foreach ($labels as $label) {
            $pattern = '/' . preg_quote($label, '/') . '\s*(.+)/i';
            if (preg_match($pattern, $output, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    protected function extractNameservers(string $output): array
    {
        $nameservers = [];
        if (preg_match_all('/Name Server:\s*(.+)/i', $output, $matches)) {
            foreach ($matches[1] as $ns) {
                $ns = strtolower(trim($ns));
                if ($ns && !in_array($ns, $nameservers)) {
                    $nameservers[] = $ns;
                }
            }
        }
        return $nameservers;
    }

    protected function extractDomainStatuses(string $output): array
    {
        $statuses = [];
        if (preg_match_all('/Domain Status:\s*(.+)/i', $output, $matches)) {
            foreach ($matches[1] as $status) {
                $statuses[] = trim($status);
            }
        }
        return $statuses;
    }

    protected function detectDnsProvider(array $nameservers): ?string
    {
        $providers = [
            'cloudflare' => 'Cloudflare',
            'awsdns' => 'AWS Route 53',
            'googledomains' => 'Google Domains',
            'google' => 'Google Cloud DNS',
            'digitalocean' => 'DigitalOcean',
            'linode' => 'Linode',
            'vultr' => 'Vultr',
            'godaddy' => 'GoDaddy',
            'namecheap' => 'Namecheap',
            'dnsimple' => 'DNSimple',
            'netlify' => 'Netlify',
            'vercel' => 'Vercel',
            'ns1.' => 'NS1',
            'dynect' => 'Dyn',
            'azure-dns' => 'Azure DNS',
        ];

        $nsString = strtolower(implode(' ', $nameservers));

        foreach ($providers as $pattern => $name) {
            if (str_contains($nsString, $pattern)) {
                return $name;
            }
        }

        return null;
    }

    protected function checkAlerts(string $status): void
    {
        if (!$this->domainMonitor->alerts_enabled) {
            return;
        }

        if (!in_array($status, ['expired', 'expiring_soon'])) {
            return;
        }

        // Rate limit to once per day
        if ($this->domainMonitor->last_alert_sent_at) {
            if ($this->domainMonitor->last_alert_sent_at->isToday()) {
                return;
            }
        }

        NotifyDomainAlert::dispatch($this->domainMonitor, $status);

        $this->domainMonitor->update(['last_alert_sent_at' => now()]);
    }
}
