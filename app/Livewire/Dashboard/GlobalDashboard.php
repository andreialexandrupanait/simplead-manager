<?php

namespace App\Livewire\Dashboard;

use App\Jobs\CheckUptime;
use App\Jobs\CreateBackup;
use App\Jobs\GenerateReport;
use App\Jobs\SyncWordPressSite;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Models\Client;
use App\Models\SiteStatus;
use App\Services\DashboardService;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class GlobalDashboard extends Component
{
    use WithPagination;

    public string $search = '';
    public string $filter = 'all';
    public ?int $statusFilter = null;
    public ?int $clientFilter = null;
    public string $sort = 'manual';
    public bool $reordering = false;
    public string $viewMode = 'list'; // 'list' or 'grid'

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
        if (!Schema::hasTable('site_statuses')) {
            return collect();
        }

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
        $this->statusFilter = $this->statusFilter === '' ? null : (int) $this->statusFilter;
        $this->resetPage();
        unset($this->sites);
    }

    public function updatedClientFilter(): void
    {
        $this->clientFilter = $this->clientFilter === '' ? null : (int) $this->clientFilter;
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
        if (!Schema::hasTable('site_statuses')) {
            return;
        }

        $site = Site::findOrFail($siteId);
        $site->update(['site_status_id' => $statusId]);
        unset($this->sites, $this->siteStatuses);
    }

    public function runBackup(int $siteId): void
    {
        $site = Site::findOrFail($siteId);
        CreateBackup::dispatch($site, 'full', 'manual');
        session()->flash('message', "Backup queued for {$site->name}.");
    }

    public function checkNow(int $siteId): void
    {
        $site = Site::findOrFail($siteId);
        if ($site->uptimeMonitor) {
            CheckUptime::dispatch($site->uptimeMonitor);
            session()->flash('message', "Uptime check queued for {$site->name}.");
        }
    }

    public function syncSite(int $siteId): void
    {
        $site = Site::findOrFail($siteId);
        SyncWordPressSite::dispatch($site);
        session()->flash('message', "Sync queued for {$site->name}.");
    }

    public function generateQuickReport(int $siteId): void
    {
        $site = Site::findOrFail($siteId);
        $template = ReportTemplate::where('is_default', true)->first() ?? ReportTemplate::first();
        if (!$template) {
            session()->flash('message', 'No report template configured.');
            return;
        }
        GenerateReport::dispatch($site, $template, now()->subDays(30)->startOfDay(), now()->endOfDay(), 'manual');
        session()->flash('message', "Report generation queued for {$site->name}.");
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
        $site->update(['name' => $this->renamingSiteName]);

        unset($this->sites);

        $this->renamingSiteId = null;
        $this->renamingSiteName = '';

        session()->flash('message', "Site renamed to \"{$site->name}\".");
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
        $siteName = $site->name;
        $site->delete();

        unset($this->sites, $this->stats);

        $this->deletingSiteId = null;
        $this->deletingSiteName = null;

        session()->flash('message', "Site \"{$siteName}\" has been deleted.");
        $this->dispatch('close-modal-delete-site');
    }

    public function clearSelection(): void
    {
        $this->selectedSites = [];
    }

    public function bulkSetStatus(int $statusId): void
    {
        Site::whereIn('id', $this->selectedSites)->update(['site_status_id' => $statusId]);
        $this->selectedSites = [];
        unset($this->sites, $this->siteStatuses);
        session()->flash('message', 'Status updated for selected sites.');
    }

    public function bulkClearStatus(): void
    {
        Site::whereIn('id', $this->selectedSites)->update(['site_status_id' => null]);
        $this->selectedSites = [];
        unset($this->sites, $this->siteStatuses);
        session()->flash('message', 'Status cleared for selected sites.');
    }

    public function bulkSync(): void
    {
        $sites = Site::whereIn('id', $this->selectedSites)->get();
        foreach ($sites as $site) {
            SyncWordPressSite::dispatch($site);
        }
        $count = $sites->count();
        $this->selectedSites = [];
        session()->flash('message', "Sync queued for {$count} sites.");
    }

    public function bulkBackup(): void
    {
        $sites = Site::whereIn('id', $this->selectedSites)->get();
        foreach ($sites as $site) {
            CreateBackup::dispatch($site, 'full', 'manual');
        }
        $count = $sites->count();
        $this->selectedSites = [];
        session()->flash('message', "Backup queued for {$count} sites.");
    }

    public function bulkCheckUptime(): void
    {
        $sites = Site::whereIn('id', $this->selectedSites)->with('uptimeMonitor')->get();
        $count = 0;
        foreach ($sites as $site) {
            if ($site->uptimeMonitor) {
                CheckUptime::dispatch($site->uptimeMonitor);
                $count++;
            }
        }
        $this->selectedSites = [];
        session()->flash('message', "Uptime check queued for {$count} sites.");
    }

    public function confirmBulkDelete(): void
    {
        $this->dispatch('open-modal-bulk-delete');
    }

    public function bulkDelete(): void
    {
        $count = count($this->selectedSites);
        Site::whereIn('id', $this->selectedSites)->delete();
        $this->selectedSites = [];
        unset($this->sites, $this->stats);
        session()->flash('message', "{$count} sites deleted.");
        $this->dispatch('close-modal-bulk-delete');
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
        if (!$this->reordering || !Schema::hasColumn('sites', 'sort_order')) {
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

        session()->flash('message', 'Sort order saved.');
        $this->redirect(route('dashboard'), navigate: true);
    }

    public function render()
    {
        return view('livewire.dashboard.global-dashboard')
            ->layout('components.layouts.app', ['title' => 'Dashboard']);
    }
}
