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
 * Backup and uptime-check already live in the components/traits the hosts use,
 * so they are not duplicated here. Delete is here because leaving it out was a
 * bug: the row's ⋮ menu calls confirmDelete() unconditionally, so on the landing
 * page it threw MethodNotFoundException — the delete option looked available and
 * did nothing but log an exception.
 */
trait WithSiteRowActions
{
    /** Rename modal state. */
    public ?int $renamingSiteId = null;

    public string $renamingSiteName = '';

    /** Delete confirmation state. */
    public ?int $deletingSiteId = null;

    public ?string $deletingSiteName = null;

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

        $this->dispatch('notify', type: 'success', message: __('Sync queued for :name.', ['name' => $site->name]));
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

        $this->dispatch('notify', type: 'success', message: __('Site renamed to ":name".', ['name' => $site->name]));
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

        unset($this->sites);

        $this->deletingSiteId = null;
        $this->deletingSiteName = null;

        $this->dispatch('notify', type: 'success', message: __('Site ":name" has been deleted.', ['name' => $siteName]));
        $this->dispatch('close-modal-delete-site');
    }

    /** Statuses offered by the row's ⋮ menu. */
    #[Computed]
    public function siteStatuses()
    {
        return SiteStatus::orderBy('sort_order')->orderBy('name')->get();
    }
}
