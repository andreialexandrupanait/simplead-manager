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

    #[Computed]
    public function stats(): array
    {
        return [
            'total' => DnsMonitor::active()->count(),
            'with_changes' => DnsMonitor::active()->where('has_changes', true)->count(),
            'recent_changes' => DnsChange::where('detected_at', '>=', now()->subWeek())->count(),
        ];
    }

    public function acknowledge(int $changeId): void
    {
        DnsChange::findOrFail($changeId)->update(['acknowledged_at' => now()]);
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
            ->orderByDesc('has_changes')
            ->orderBy('domain')
            ->paginate(50);

        return view('livewire.dns.dns-overview', [
            'monitors' => $monitors,
            'changes' => collect(),
        ])->layout('components.layouts.app', ['title' => 'DNS Monitoring']);
    }
}
