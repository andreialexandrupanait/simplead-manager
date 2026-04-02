<?php

declare(strict_types=1);

namespace App\Livewire\Dashboard;

use App\Jobs\CheckUptime;
use App\Jobs\CreateBackup;
use App\Jobs\GenerateReport;
use App\Jobs\SyncWordPressSite;
use App\Livewire\Traits\WithBulkSiteActions;
use App\Livewire\Traits\WithRateLimiting;
use App\Models\Client;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Models\SiteStatus;
use App\Services\DashboardService;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class GlobalDashboard extends Component
{
    use WithBulkSiteActions, WithPagination, WithRateLimiting;

    public string $search = '';

    public string $filter = 'all';

    public ?int $statusFilter = null;

    public ?int $clientFilter = null;

    public string $sort = 'manual';

    public bool $reordering = false;

    // Selection
    public array $selectedSites = [];

    // Rename modal
    public ?int $renamingSiteId = null;

    public string $renamingSiteName = '';

    // Delete modal
    public ?int $deletingSiteId = null;

    public ?string $deletingSiteName = null;

    #[Computed]
    public function stats(): array
    {
        return app(DashboardService::class)->getStats();
    }

    #[Computed]
    public function sites()
    {
        return app(DashboardService::class)->getSitesOverview(30, $this->search, $this->filter, $this->statusFilter, $this->clientFilter, $this->sort);
    }

    #[Computed]
    public function clients()
    {
        return Client::withCount('sites')->orderBy('name')->get();
    }

    #[Computed]
    public function siteStatuses()
    {
        return SiteStatus::withCount('sites')->orderBy('sort_order')->get();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
        unset($this->sites);
    }

    public function updatedStatusFilter(): void
    {
        $this->statusFilter = empty($this->statusFilter) ? null : (int) $this->statusFilter;
        $this->resetPage();
        unset($this->sites);
    }

    public function updatedClientFilter(): void
    {
        $this->clientFilter = empty($this->clientFilter) ? null : (int) $this->clientFilter;
        $this->resetPage();
        unset($this->sites);
    }

    public function updatedSort(): void
    {
        $this->resetPage();
        unset($this->sites);
    }

    public function toggleSiteSelection(int $siteId): void
    {
        if (in_array($siteId, $this->selectedSites)) {
            $this->selectedSites = array_values(array_diff($this->selectedSites, [$siteId]));
        } else {
            $this->selectedSites[] = $siteId;
        }
    }

    public function toggleSelectAll(): void
    {
        $siteIds = $this->sites->pluck('id')->toArray();

        if (count(array_intersect($this->selectedSites, $siteIds)) === count($siteIds)) {
            $this->selectedSites = array_values(array_diff($this->selectedSites, $siteIds));
        } else {
            $this->selectedSites = array_values(array_unique(array_merge($this->selectedSites, $siteIds)));
        }
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->resetPage();
        unset($this->sites);
    }

    public function setStatusFilter(?int $statusId): void
    {
        $this->statusFilter = $statusId;
        $this->resetPage();
        unset($this->sites);
    }

    public function setClientFilter(?int $clientId): void
    {
        $this->clientFilter = $clientId;
        $this->resetPage();
        unset($this->sites);
    }

    public function setSort(string $sort): void
    {
        $this->sort = $sort;
        $this->resetPage();
        unset($this->sites);
    }

    public function setSiteStatus(int $siteId, ?int $statusId): void
    {
        $site = Site::findOrFail($siteId);
        $this->authorize('update', $site);
        $site->update(['site_status_id' => $statusId]);
        unset($this->sites, $this->siteStatuses);
    }

    public function runBackup(int $siteId): void
    {
        if (! $this->rateLimit('backup', $siteId)) {
            return;
        }

        /** @var Site $site */
        $site = Site::findOrFail($siteId);
        $this->authorize('update', $site);
        CreateBackup::dispatch($site, 'full', 'manual');
        $this->dispatch('notify', type: 'success', message: "Backup queued for {$site->name}.");
    }

    public function checkNow(int $siteId): void
    {
        if (! $this->rateLimit('uptime-check', $siteId, 10)) {
            return;
        }

        /** @var Site $site */
        $site = Site::findOrFail($siteId);
        $this->authorize('update', $site);
        if ($site->uptimeMonitor) {
            /** @var \App\Models\UptimeMonitor $uptimeMonitor */
            $uptimeMonitor = $site->uptimeMonitor;
            CheckUptime::dispatch($uptimeMonitor);
            $this->dispatch('notify', type: 'success', message: "Uptime check queued for {$site->name}.");
        }
    }

    public function syncSite(int $siteId): void
    {
        if (! $this->rateLimit('sync', $siteId, 10)) {
            return;
        }

        /** @var Site $site */
        $site = Site::findOrFail($siteId);
        $this->authorize('update', $site);
        SyncWordPressSite::dispatch($site);
        $this->dispatch('notify', type: 'success', message: "Sync queued for {$site->name}.");
    }

    public function generateQuickReport(int $siteId): void
    {
        if (! $this->rateLimit('report', $siteId, 10)) {
            return;
        }

        $site = Site::findOrFail($siteId);
        $this->authorize('update', $site);
        $template = ReportTemplate::where('is_default', true)->first() ?? ReportTemplate::first();
        if (! $template) {
            $this->dispatch('notify', type: 'error', message: 'No report template configured.');

            return;
        }
        GenerateReport::dispatch($site, $template, now()->subDays(30)->startOfDay(), now()->endOfDay(), 'manual');
        $this->dispatch('notify', type: 'success', message: "Report generation queued for {$site->name}.");
    }

    public function startRename(int $siteId, string $currentName): void
    {
        $this->renamingSiteId = $siteId;
        $this->renamingSiteName = $currentName;
        $this->resetValidation();
        $this->dispatch('open-modal-rename-site');
    }

    public function renameSite(): void
    {
        $this->validate([
            'renamingSiteName' => 'required|string|max:255',
        ]);

        $site = Site::findOrFail($this->renamingSiteId);
        $this->authorize('update', $site);
        $site->update(['name' => $this->renamingSiteName]);

        unset($this->sites);

        $this->renamingSiteId = null;
        $this->renamingSiteName = '';

        $this->dispatch('notify', type: 'success', message: "Site renamed to \"{$site->name}\".");
        $this->dispatch('close-modal-rename-site');
    }

    public function confirmDelete(int $siteId, string $siteName): void
    {
        $this->deletingSiteId = $siteId;
        $this->deletingSiteName = $siteName;
        $this->dispatch('open-modal-delete-site');
    }

    public function deleteSite(): void
    {
        $site = Site::findOrFail($this->deletingSiteId);
        $this->authorize('delete', $site);
        $siteName = $site->name;
        $site->delete();

        unset($this->sites, $this->stats);

        $this->deletingSiteId = null;
        $this->deletingSiteName = null;

        $this->dispatch('notify', type: 'success', message: "Site \"{$siteName}\" has been deleted.");
        $this->dispatch('close-modal-delete-site');
        $this->resetPage();
    }

    public function clearSelection(): void
    {
        $this->selectedSites = [];
    }

    public function startReordering(): void
    {
        $this->reordering = true;
        $this->sort = 'manual';
        unset($this->sites);
    }

    public function cancelReordering(): void
    {
        $this->reordering = false;
        unset($this->sites);
    }

    public function saveReorder(array $orderedIds): void
    {
        abort_unless(auth()->user()->canManageSites(), 403);

        if (! $this->reordering) {
            return;
        }

        $page = $this->sites->currentPage();
        $perPage = $this->sites->perPage();
        $offset = ($page - 1) * $perPage;

        foreach ($orderedIds as $index => $siteId) {
            Site::where('id', $siteId)->update(['sort_order' => $offset + $index + 1]);
        }

        $this->reordering = false;
        unset($this->sites);

        $this->dispatch('notify', type: 'success', message: 'Sort order saved.');
    }

    public function render()
    {
        return view('livewire.dashboard.global-dashboard')
            ->layout('components.layouts.app', ['title' => 'Dashboard']);
    }
}
