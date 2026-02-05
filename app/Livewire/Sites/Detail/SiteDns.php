<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\CheckEmailDeliverabilityJob;
use App\Models\DnsRecordCache;
use App\Models\Site;
use App\Services\DnsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteDns extends Component
{
    public Site $site;
    public bool $loading = false;
    public bool $emailCheckLoading = false;

    public function mount(Site $site): void
    {
        $this->site = $site;

        // Auto-fetch on first visit if no cache exists
        if (! $this->site->dnsRecordCache) {
            $this->refresh();
        }
    }

    #[Computed]
    public function dnsCache(): ?DnsRecordCache
    {
        return $this->site->dnsRecordCache;
    }

    #[Computed]
    public function stats(): array
    {
        $cache = $this->dnsCache;

        if (! $cache) {
            return [
                'total_records' => 0,
                'uses_cloudflare' => false,
                'mail_provider' => null,
                'email_security_score' => 0,
            ];
        }

        return [
            'total_records' => $cache->total_records,
            'uses_cloudflare' => $cache->uses_cloudflare,
            'mail_provider' => $cache->mail_provider,
            'email_security_score' => $cache->email_security_score,
        ];
    }

    #[Computed]
    public function recordGroups(): array
    {
        $cache = $this->dnsCache;

        if (! $cache) {
            return [];
        }

        $groups = [];

        if (! empty($cache->a_records)) {
            $groups['A'] = $cache->a_records;
        }
        if (! empty($cache->aaaa_records)) {
            $groups['AAAA'] = $cache->aaaa_records;
        }
        if (! empty($cache->cname_records)) {
            $groups['CNAME'] = $cache->cname_records;
        }
        if (! empty($cache->mx_records)) {
            $groups['MX'] = $cache->mx_records;
        }
        if (! empty($cache->txt_records)) {
            $groups['TXT'] = $cache->txt_records;
        }
        if (! empty($cache->ns_records)) {
            $groups['NS'] = $cache->ns_records;
        }
        if (! empty($cache->soa_record)) {
            $groups['SOA'] = [$cache->soa_record];
        }

        return $groups;
    }

    #[Computed]
    public function emailHealth()
    {
        return $this->site->latestEmailHealthCheck;
    }

    #[Computed]
    public function emailRecommendations(): array
    {
        return $this->emailHealth?->recommendations ?? [];
    }

    public function refresh(): void
    {
        $this->loading = true;

        try {
            DnsService::fetchAndCache($this->site);
            $this->site->load('dnsRecordCache');
            unset($this->dnsCache, $this->stats, $this->recordGroups);
        } finally {
            $this->loading = false;
        }
    }

    public function checkEmailHealth(): void
    {
        CheckEmailDeliverabilityJob::dispatch($this->site);

        session()->flash('success', 'Email deliverability check queued. Results will appear shortly.');
    }

    public function render()
    {
        return view('livewire.sites.detail.site-dns')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — DNS',
            ]);
    }
}
