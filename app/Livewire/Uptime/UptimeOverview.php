<?php

declare(strict_types=1);

namespace App\Livewire\Uptime;

use App\Jobs\CheckUptime;
use App\Models\Site;
use App\Models\UptimeMonitor;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class UptimeOverview extends Component
{
    use WithPagination;

    public string $search = '';

    // Maintenance window modal
    public ?int $maintenanceMonitorId = null;

    public string $maintenanceStartsAt = '';

    public string $maintenanceEndsAt = '';

    public string $maintenanceReason = '';

    #[Url]
    public string $filter = 'all';

    public function updatedSearch(): void
    {
        // Reset filter when searching
    }

    public function getCountsProperty(): array
    {
        $counts = UptimeMonitor::whereHas('site')
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN current_state = 'up' THEN 1 ELSE 0 END) as up,
                SUM(CASE WHEN current_state = 'down' THEN 1 ELSE 0 END) as down,
                SUM(CASE WHEN current_state = 'degraded' THEN 1 ELSE 0 END) as degraded,
                SUM(CASE WHEN status = 'paused' THEN 1 ELSE 0 END) as paused
            ")
            ->first();

        return [
            'total' => (int) $counts->total,
            'up' => (int) $counts->up,
            'down' => (int) $counts->down,
            'degraded' => (int) $counts->degraded,
            'paused' => (int) $counts->paused,
        ];
    }

    public function pauseMonitor(int $id): void
    {
        UptimeMonitor::whereHas('site')->findOrFail($id)->update(['status' => 'paused']);
    }

    public function resumeMonitor(int $id): void
    {
        UptimeMonitor::whereHas('site')->findOrFail($id)->update([
            'status' => 'active',
            'next_check_at' => now(),
        ]);
    }

    public function testMonitor(int $id): void
    {
        $monitor = UptimeMonitor::whereHas('site')->findOrFail($id);
        CheckUptime::dispatch($monitor);
    }

    public function deleteMonitor(int $id): void
    {
        UptimeMonitor::whereHas('site')->findOrFail($id)->delete();
    }

    public function openMaintenanceModal(int $id): void
    {
        $monitor = UptimeMonitor::findOrFail($id);
        $this->maintenanceMonitorId = $id;
        $this->maintenanceStartsAt = $monitor->maintenance_starts_at?->format('Y-m-d\TH:i') ?? now()->format('Y-m-d\TH:i');
        $this->maintenanceEndsAt = $monitor->maintenance_ends_at?->format('Y-m-d\TH:i') ?? now()->addHours(2)->format('Y-m-d\TH:i');
        $this->maintenanceReason = $monitor->maintenance_reason ?? '';
        $this->dispatch('open-modal-maintenance-window');
    }

    public function setMaintenanceWindow(): void
    {
        $this->validate([
            'maintenanceStartsAt' => 'required|date',
            'maintenanceEndsAt' => 'required|date|after:maintenanceStartsAt',
            'maintenanceReason' => 'nullable|string|max:255',
        ]);

        UptimeMonitor::findOrFail($this->maintenanceMonitorId)->update([
            'maintenance_starts_at' => $this->maintenanceStartsAt,
            'maintenance_ends_at' => $this->maintenanceEndsAt,
            'maintenance_reason' => $this->maintenanceReason ?: null,
        ]);

        $this->dispatch('close-modal-maintenance-window');
        $this->reset('maintenanceMonitorId', 'maintenanceStartsAt', 'maintenanceEndsAt', 'maintenanceReason');
    }

    public function clearMaintenanceWindow(int $id): void
    {
        UptimeMonitor::findOrFail($id)->clearMaintenanceWindow();
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
            /** @var UptimeMonitor $monitor */
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
                $q->whereHas('site', fn ($sq) => $sq->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('url', 'ilike', "%{$this->search}%"));
            })
            ->when($this->filter !== 'all', fn ($q) => match ($this->filter) {
                'up' => $q->where('current_state', 'up'),
                'down' => $q->where('current_state', 'down'),
                'degraded' => $q->where('current_state', 'degraded'),
                'paused' => $q->where('uptime_monitors.status', 'paused'),
                default => $q,
            })
            ->join('sites', 'uptime_monitors.site_id', '=', 'sites.id')
            ->orderBy('sites.sort_order', 'asc')
            ->select('uptime_monitors.*')
            ->paginate(50);

        return view('livewire.uptime.uptime-overview', compact('monitors'))
            ->layout('components.layouts.app', ['title' => 'Uptime']);
    }
}
