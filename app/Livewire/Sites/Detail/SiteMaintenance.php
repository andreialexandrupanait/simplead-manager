<?php

namespace App\Livewire\Sites\Detail;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\MaintenanceWindow;
use App\Models\Site;
use App\Services\MaintenanceService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class SiteMaintenance extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    // Modal state
    #[Locked]
    public ?int $editingId = null;

    // Form fields
    public string $title = '';
    public string $description = '';
    public string $scheduledStartAt = '';
    public string $scheduledEndAt = '';
    public bool $pauseUptime = true;
    public bool $pauseSsl = false;
    public bool $pausePerformance = false;
    public bool $pauseBackups = false;
    public bool $pauseLinks = false;
    public bool $notifyOnStart = true;
    public bool $notifyOnEnd = true;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function activeMaintenance(): ?MaintenanceWindow
    {
        return $this->site->maintenanceWindows()
            ->where('status', 'active')
            ->first();
    }

    #[Computed]
    public function upcomingWindows()
    {
        return $this->site->maintenanceWindows()
            ->where('status', 'scheduled')
            ->orderBy('scheduled_start_at')
            ->get();
    }

    #[Computed]
    public function pastWindows()
    {
        return $this->site->maintenanceWindows()
            ->whereIn('status', ['completed', 'cancelled'])
            ->orderByDesc('scheduled_start_at')
            ->limit(20)
            ->get();
    }

    public function openCreateModal(): void
    {
        $this->resetForm();
        $this->editingId = null;
        $this->scheduledStartAt = now()->addMinutes(5)->format('Y-m-d\TH:i');
        $this->scheduledEndAt = now()->addHour()->format('Y-m-d\TH:i');
        $this->dispatch('open-modal-maintenance-form');
    }

    public function openEditModal(int $id): void
    {
        $window = MaintenanceWindow::findOrFail($id);
        $this->editingId = $id;
        $this->title = $window->title;
        $this->description = $window->description ?? '';
        $this->scheduledStartAt = $window->scheduled_start_at->format('Y-m-d\TH:i');
        $this->scheduledEndAt = $window->scheduled_end_at->format('Y-m-d\TH:i');
        $this->pauseUptime = $window->pause_uptime;
        $this->pauseSsl = $window->pause_ssl;
        $this->pausePerformance = $window->pause_performance;
        $this->pauseBackups = $window->pause_backups;
        $this->pauseLinks = $window->pause_links;
        $this->notifyOnStart = $window->notify_on_start;
        $this->notifyOnEnd = $window->notify_on_end;
        $this->dispatch('open-modal-maintenance-form');
    }

    public function save(): void
    {
        $this->validate([
            'title' => 'required|string|max:255',
            'scheduledStartAt' => 'required|date',
            'scheduledEndAt' => 'required|date|after:scheduledStartAt',
        ]);

        $data = [
            'site_id' => $this->site->id,
            'user_id' => auth()->id(),
            'title' => $this->title,
            'description' => $this->description ?: null,
            'scheduled_start_at' => $this->scheduledStartAt,
            'scheduled_end_at' => $this->scheduledEndAt,
            'pause_uptime' => $this->pauseUptime,
            'pause_ssl' => $this->pauseSsl,
            'pause_performance' => $this->pausePerformance,
            'pause_backups' => $this->pauseBackups,
            'pause_links' => $this->pauseLinks,
            'notify_on_start' => $this->notifyOnStart,
            'notify_on_end' => $this->notifyOnEnd,
        ];

        if ($this->editingId) {
            MaintenanceWindow::findOrFail($this->editingId)->update($data);
        } else {
            MaintenanceWindow::create($data);
        }

        $this->dispatch('close-modal-maintenance-form');
        unset($this->activeMaintenance, $this->upcomingWindows, $this->pastWindows);
    }

    public function startNow(int $id): void
    {
        $window = MaintenanceWindow::findOrFail($id);
        MaintenanceService::startMaintenance($window);
        unset($this->activeMaintenance, $this->upcomingWindows, $this->pastWindows);
    }

    public function endNow(): void
    {
        $window = $this->activeMaintenance;
        if ($window) {
            MaintenanceService::endMaintenance($window);
        }
        unset($this->activeMaintenance, $this->upcomingWindows, $this->pastWindows);
    }

    public function cancel(int $id): void
    {
        $window = MaintenanceWindow::findOrFail($id);
        MaintenanceService::cancelMaintenance($window);
        unset($this->activeMaintenance, $this->upcomingWindows, $this->pastWindows);
    }

    protected function resetForm(): void
    {
        $this->title = '';
        $this->description = '';
        $this->scheduledStartAt = '';
        $this->scheduledEndAt = '';
        $this->pauseUptime = true;
        $this->pauseSsl = false;
        $this->pausePerformance = false;
        $this->pauseBackups = false;
        $this->pauseLinks = false;
        $this->notifyOnStart = true;
        $this->notifyOnEnd = true;
    }

    public function render()
    {
        return view('livewire.sites.detail.site-maintenance')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Maintenance',
            ]);
    }
}
