<?php

namespace App\Livewire\Uptime;

use App\Jobs\CheckUptime;
use App\Models\Site;
use App\Models\UptimeMonitor;
use Livewire\Attributes\On;
use Livewire\Component;

class UptimeOverview extends Component
{
    public string $search = '';
    public string $filter = 'all';

    public function updatedSearch(): void
    {
        // Reset filter when searching
    }

    public function getCountsProperty(): array
    {
        return [
            'total' => UptimeMonitor::whereHas('site')->count(),
            'up' => UptimeMonitor::whereHas('site')->where('current_state', 'up')->count(),
            'down' => UptimeMonitor::whereHas('site')->where('current_state', 'down')->count(),
            'degraded' => UptimeMonitor::whereHas('site')->where('current_state', 'degraded')->count(),
            'paused' => UptimeMonitor::whereHas('site')->where('status', 'paused')->count(),
        ];
    }

    public function pauseMonitor(int $id): void
    {
        UptimeMonitor::findOrFail($id)->update(['status' => 'paused']);
    }

    public function resumeMonitor(int $id): void
    {
        UptimeMonitor::findOrFail($id)->update([
            'status' => 'active',
            'next_check_at' => now(),
        ]);
    }

    public function testMonitor(int $id): void
    {
        $monitor = UptimeMonitor::findOrFail($id);
        CheckUptime::dispatch($monitor);
    }

    public function deleteMonitor(int $id): void
    {
        UptimeMonitor::findOrFail($id)->delete();
    }

    public function getSitesWithoutMonitorCountProperty(): int
    {
        return Site::whereDoesntHave('uptimeMonitor')->count();
    }

    public function addMonitorsForAllSites(): void
    {
        $sites = Site::whereDoesntHave('uptimeMonitor')->get();
        $created = 0;

        foreach ($sites as $site) {
            $monitor = $site->uptimeMonitor()->create([
                'url' => $site->url,
            ]);
            CheckUptime::dispatch($monitor);
            $created++;
        }

        session()->flash('message', "{$created} uptime monitor(s) created.");
    }

    #[On('monitor-saved')]
    public function refreshMonitors(): void
    {
        // Livewire will re-render automatically
    }

    public function render()
    {
        $monitors = UptimeMonitor::query()
            ->whereHas('site')
            ->with('site')
            ->when($this->search, function ($q) {
                $q->whereHas('site', fn ($sq) => $sq->where('name', 'ilike', "%{$this->search}%"))
                  ->orWhere('url', 'ilike', "%{$this->search}%");
            })
            ->when($this->filter !== 'all', fn ($q) => match ($this->filter) {
                'up' => $q->where('current_state', 'up'),
                'down' => $q->where('current_state', 'down'),
                'degraded' => $q->where('current_state', 'degraded'),
                'paused' => $q->where('status', 'paused'),
                default => $q,
            })
            ->orderByDesc('updated_at')
            ->get();

        return view('livewire.uptime.uptime-overview', compact('monitors'))
            ->layout('components.layouts.app', ['title' => 'Uptime']);
    }
}
