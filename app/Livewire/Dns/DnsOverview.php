<?php

declare(strict_types=1);

namespace App\Livewire\Dns;

use App\Jobs\CheckDns;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\DnsChange;
use App\Models\DnsMonitor;
use App\Services\DnsSelectorDiscoveryService;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class DnsOverview extends Component
{
    use WithPagination, WithSiteAuthorization;

    public string $tab = 'monitors';

    public string $search = '';

    public ?int $expandedMonitor = null;

    public ?int $editingSelectorsFor = null;

    public string $selectorsInput = '';

    #[Computed]
    public function stats(): array
    {
        $monitors = DnsMonitor::active()->whereNotNull('current_records')->get();

        $noSpf = 0;
        $noDmarc = 0;
        $noDkim = 0;
        $usesCloudflare = 0;

        foreach ($monitors as $m) {
            $records = $m->current_records ?? [];
            $txtRecords = implode(' ', $records['TXT'] ?? []);
            $nsRecords = implode(' ', $records['NS'] ?? []);

            if (stripos($txtRecords, 'v=spf1') === false) {
                $noSpf++;
            }
            if (empty($records['DMARC'] ?? [])) {
                $noDmarc++;
            }
            if (empty($records['DKIM'] ?? [])) {
                $noDkim++;
            }
            if (stripos($nsRecords, 'cloudflare') !== false) {
                $usesCloudflare++;
            }
        }

        return [
            'total' => DnsMonitor::active()->count(),
            'with_changes' => DnsMonitor::active()->where('has_changes', true)->count(),
            'no_spf' => $noSpf,
            'no_dmarc' => $noDmarc,
            'no_dkim' => $noDkim,
            'cloudflare' => $usesCloudflare,
            'recent_changes' => DnsChange::where('detected_at', '>=', now()->subWeek())->count(),
        ];
    }

    public function toggleExpand(int $monitorId): void
    {
        $this->expandedMonitor = $this->expandedMonitor === $monitorId ? null : $monitorId;
    }

    public function acknowledge(int $changeId): void
    {
        $change = DnsChange::with('monitor.site')->findOrFail($changeId);
        $site = $change->monitor?->site;
        abort_if($site === null, 404, 'Site no longer exists.');
        $this->authorizeSiteModification($site);
        $change->update(['acknowledged_at' => now()]);
    }

    public function recheckAll(): void
    {
        abort_if((bool) auth()->user()?->isViewer(), 403, 'Viewers cannot modify sites.');

        DnsMonitor::active()->update(['next_check_at' => now()]);
        $this->dispatch('notify', type: 'success', message: 'DNS recheck queued for all monitors.');
    }

    public function rediscoverSelectors(int $monitorId): void
    {
        $monitor = DnsMonitor::with('site.siteCloudflare.cloudflareConnection')->findOrFail($monitorId);
        abort_if($monitor->site === null, 404, 'Site no longer exists.');
        $this->authorizeSiteModification($monitor->site);

        $discovery = app(DnsSelectorDiscoveryService::class);
        $sources = $discovery->discoverFor($monitor);

        $discovered = array_values(array_unique(array_merge(
            $sources['cloudflare'] ?? [],
            $sources['postmark'] ?? [],
        )));

        if ($discovered === []) {
            $this->dispatch('notify', type: 'info', message: __('No selectors discovered from Cloudflare/Postmark for this domain.'));
            CheckDns::dispatch($monitor);

            return;
        }

        $existing = is_array($monitor->dkim_selectors) ? $monitor->dkim_selectors : [];
        $merged = array_values(array_unique(array_merge($existing, $discovered)));

        $monitor->update([
            'dkim_selectors' => array_slice($merged, 0, 20),
            'next_check_at' => now(),
        ]);

        $added = count($merged) - count($existing);
        $this->dispatch('notify', type: 'success', message: __(':count selectors discovered (:new new). Recheck queued.', [
            'count' => count($discovered),
            'new' => $added,
        ]));
    }

    public function editSelectors(int $monitorId): void
    {
        $monitor = DnsMonitor::findOrFail($monitorId);
        $this->editingSelectorsFor = $monitorId;
        $this->selectorsInput = implode(', ', $monitor->dkim_selectors ?? []);
    }

    public function cancelEditSelectors(): void
    {
        $this->editingSelectorsFor = null;
        $this->selectorsInput = '';
    }

    public function saveSelectors(int $monitorId): void
    {
        $monitor = DnsMonitor::findOrFail($monitorId);
        abort_if($monitor->site === null, 404, 'Site no longer exists.');
        $this->authorizeSiteModification($monitor->site);

        $raw = preg_split('/[\s,]+/', mb_strtolower(trim($this->selectorsInput))) ?: [];
        $selectors = [];

        foreach ($raw as $selector) {
            $selector = trim($selector);

            if ($selector === '') {
                continue;
            }

            if (mb_strlen($selector) > 63 || ! preg_match('/^[a-z0-9._-]+$/', $selector)) {
                $this->dispatch('notify', type: 'error', message: "Invalid selector: {$selector}");

                return;
            }

            $selectors[] = $selector;
        }

        $selectors = array_values(array_unique($selectors));

        if (count($selectors) > 20) {
            $this->dispatch('notify', type: 'error', message: 'Maximum 20 selectors allowed.');

            return;
        }

        $monitor->update([
            'dkim_selectors' => $selectors,
            'next_check_at' => now(),
        ]);

        $this->editingSelectorsFor = null;
        $this->selectorsInput = '';
        $this->dispatch('notify', type: 'success', message: 'DKIM selectors saved. Recheck queued.');
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        if ($this->tab === 'changes') {
            $changes = DnsChange::with('monitor.site')
                ->orderByDesc('detected_at')
                ->paginate(50);

            return view('livewire.dns.dns-overview', [
                'changes' => $changes,
                'monitors' => collect(),
            ])->layout('components.layouts.app', ['title' => 'DNS Monitoring']);
        }

        $monitors = DnsMonitor::with('site')
            ->active()
            ->when($this->search, function ($q) {
                $s = '%'.str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $this->search).'%';
                $q->where(function ($sq) use ($s) {
                    $sq->where('domain', 'ilike', $s)
                        ->orWhereHas('site', fn ($site) => $site->where('name', 'ilike', $s));
                });
            })
            ->join('sites', 'dns_monitors.site_id', '=', 'sites.id')
            ->whereNull('sites.deleted_at')
            ->orderByDesc('has_changes')
            ->orderBy('sites.sort_order')
            ->select('dns_monitors.*')
            ->paginate(50);

        return view('livewire.dns.dns-overview', [
            'monitors' => $monitors,
            'changes' => collect(),
        ])->layout('components.layouts.app', ['title' => 'DNS Monitoring']);
    }
}
