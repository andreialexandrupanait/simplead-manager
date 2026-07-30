<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Jobs\SyncWordPressSite;
use App\Models\Site;
use App\Models\SiteStatus;
use Livewire\Attributes\Computed;

/**
 * The per-row actions the fleet site row offers: select, rename, set status and
 * sync.
 *
 * These lived only on GlobalDashboard, so the rich row could only be used
 * there — which is why the landing page ended up with a thinner row that
 * hard-codes three of its eight signals to "not applicable". Shared here so
 * both screens offer the same actions rather than two divergent copies.
 *
 * Backup, uptime-check and delete already live in the components/traits the
 * hosts use, so they are deliberately not duplicated here.
 */
trait WithSiteRowActions
{
    /** Rename modal state. */
    public ?int $renamingSiteId = null;

    public string $renamingSiteName = '';

    public function toggleSiteSelection(int $siteId): void
    {
        if (in_array($siteId, $this->selectedSites, true)) {
            $this->selectedSites = array_values(array_diff($this->selectedSites, [$siteId]));

            return;
        }

        $this->selectedSites[] = $siteId;
    }

    public function setSiteStatus(int $siteId, ?int $statusId): void
    {
        $site = Site::findOrFail($siteId);
        $this->authorize('update', $site);
        $site->update(['site_status_id' => $statusId]);

        unset($this->sites, $this->siteStatuses);
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

        $this->dispatch('notify', type: 'success', message: __('Sincronizare pusă în coadă pentru :name.', ['name' => $site->name]));
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
        $this->validate(['renamingSiteName' => 'required|string|max:255']);

        $site = Site::findOrFail($this->renamingSiteId);
        $this->authorize('update', $site);
        $site->update(['name' => $this->renamingSiteName]);

        unset($this->sites);

        $this->renamingSiteId = null;
        $this->renamingSiteName = '';

        $this->dispatch('notify', type: 'success', message: __('Site redenumit în „:name".', ['name' => $site->name]));
        $this->dispatch('close-modal-rename-site');
    }

    /** Statuses offered by the row's ⋮ menu. */
    #[Computed]
    public function siteStatuses()
    {
        return SiteStatus::orderBy('sort_order')->orderBy('name')->get();
    }
}
