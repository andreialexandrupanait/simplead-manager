<?php

declare(strict_types=1);

namespace App\Livewire\Dns;

use App\Models\DnsChange;
use App\Models\DnsMonitor;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class DnsOverview extends Component
{
    use WithPagination;

    public string $tab = 'monitors';

    public string $search = '';

    public ?int $expandedMonitor = null;

    #[Computed]
    public function stats(): array
    {
        $monitors = DnsMonitor::active()->whereNotNull('current_records')->get();

        $noSpf = 0;
        $noDmarc = 0;
        $usesCloudflare = 0;

        foreach ($monitors as $m) {
            $records = $m->current_records ?? [];
            $txtRecords = implode(' ', $records['TXT'] ?? []);
            $nsRecords = implode(' ', $records['NS'] ?? []);

            if (stripos($txtRecords, 'v=spf1') === false) {
                $noSpf++;
            }
            if (stripos($txtRecords, 'v=DMARC1') === false) {
                $noDmarc++;
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
        DnsChange::findOrFail($changeId)->update(['acknowledged_at' => now()]);
    }

    public function recheckAll(): void
    {
        DnsMonitor::active()->update(['next_check_at' => now()]);
        $this->dispatch('notify', type: 'success', message: 'DNS recheck queued for all monitors.');
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
                $s = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $this->search) . '%';
                $q->where(function ($sq) use ($s) {
                    $sq->where('domain', 'ilike', $s)
                        ->orWhereHas('site', fn ($site) => $site->where('name', 'ilike', $s));
                });
            })
            ->orderByDesc('has_changes')
            ->orderBy('domain')
            ->paginate(50);

        return view('livewire.dns.dns-overview', [
            'monitors' => $monitors,
            'changes' => collect(),
        ])->layout('components.layouts.app', ['title' => 'DNS Monitoring']);
    }
}
