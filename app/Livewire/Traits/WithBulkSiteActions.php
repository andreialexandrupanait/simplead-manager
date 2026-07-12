<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Jobs\CheckUptime;
use App\Jobs\CreateBackup;
use App\Jobs\SyncWordPressSite;
use App\Models\Site;
use Illuminate\Support\Str;

trait WithBulkSiteActions
{
    /** @return \Illuminate\Database\Eloquent\Builder<Site> */
    protected function scopedSiteQuery(array $siteIds): \Illuminate\Database\Eloquent\Builder
    {
        return Site::whereIn('id', $siteIds)->visibleTo(auth()->user());
    }

    public function bulkMoveToClient(int $clientId): void
    {
        abort_unless(auth()->user()->canManageSites(), 403);
        $this->scopedSiteQuery($this->selectedSites)->update(['client_id' => $clientId]);
        $count = count($this->selectedSites);
        $this->selectedSites = [];
        unset($this->sites, $this->clients);
        $this->dispatch('notify', type: 'success', message: "{$count} ".Str::plural('site', $count).' moved to client.');
    }

    public function bulkSetStatus(int $statusId): void
    {
        abort_unless(auth()->user()->canManageSites(), 403);
        $this->scopedSiteQuery($this->selectedSites)->update(['site_status_id' => $statusId]);
        $this->selectedSites = [];
        unset($this->sites, $this->siteStatuses);
        $this->dispatch('notify', type: 'success', message: 'Status updated for selected sites.');
    }

    public function bulkClearStatus(): void
    {
        abort_unless(auth()->user()->canManageSites(), 403);
        $this->scopedSiteQuery($this->selectedSites)->update(['site_status_id' => null]);
        $this->selectedSites = [];
        unset($this->sites, $this->siteStatuses);
        $this->dispatch('notify', type: 'success', message: 'Status cleared for selected sites.');
    }

    public function bulkSync(): void
    {
        abort_unless(auth()->user()->canManageSites(), 403);

        if (! $this->rateLimit('bulk-sync', 'all')) {
            return;
        }

        $sites = $this->scopedSiteQuery($this->selectedSites)->get();
        foreach ($sites as $site) {
            SyncWordPressSite::dispatch($site);
        }
        $count = $sites->count();
        $this->selectedSites = [];
        $this->dispatch('notify', type: 'success', message: "Sync queued for {$count} sites.");
    }

    public function bulkBackup(): void
    {
        abort_unless(auth()->user()->canManageSites(), 403);

        if (! $this->rateLimit('bulk-backup', 'all')) {
            return;
        }

        $sites = $this->scopedSiteQuery($this->selectedSites)->get();
        foreach ($sites as $site) {
            CreateBackup::dispatch($site, 'full', 'manual');
        }
        $count = $sites->count();
        $this->selectedSites = [];
        $this->dispatch('notify', type: 'success', message: "Backup queued for {$count} sites.");
    }

    public function bulkCheckUptime(): void
    {
        abort_unless(auth()->user()->canManageSites(), 403);

        if (! $this->rateLimit('bulk-uptime', 'all')) {
            return;
        }

        $sites = $this->scopedSiteQuery($this->selectedSites)->with('uptimeMonitor')->get();
        $count = 0;
        foreach ($sites as $site) {
            if ($site->uptimeMonitor) {
                CheckUptime::dispatch($site->uptimeMonitor);
                $count++;
            }
        }
        $this->selectedSites = [];
        $this->dispatch('notify', type: 'success', message: "Uptime check queued for {$count} sites.");
    }

    public function confirmBulkDelete(): void
    {
        $this->dispatch('open-modal-bulk-delete');
    }

    public function bulkDelete(): void
    {
        abort_unless(auth()->user()->canDeleteResources(), 403);
        // Only delete sites the acting user may actually see/modify — the
        // selection is client-controlled, so an unscoped whereIn would let a
        // non-admin delete another tenant's sites (canonical visibleTo scope).
        $count = $this->scopedSiteQuery($this->selectedSites)->delete();
        $this->selectedSites = [];
        unset($this->sites, $this->stats);
        $this->dispatch('notify', type: 'success', message: "{$count} sites deleted.");
        $this->dispatch('close-modal-bulk-delete');
    }
}
