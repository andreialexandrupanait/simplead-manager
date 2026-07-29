<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Enums\HealthLevel;
use App\Jobs\CheckUptime;
use App\Jobs\CreateBackup;
use App\Livewire\Traits\WithBulkSiteActions;
use App\Livewire\Traits\WithRateLimiting;
use App\Livewire\Traits\WithTableFilters;
use App\Models\Site;
use App\Models\Tag;
use App\Services\SettingsService;
use App\Services\WordPressApiServiceFactory;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Session;
use Livewire\Attributes\Url;
use Livewire\Component;

class SitesList extends Component
{
    use WithBulkSiteActions, WithRateLimiting, WithTableFilters;

    protected $listeners = ['site-deleted' => '$refresh'];

    #[Url]
    public ?int $tagId = null;

    /** Persisted list/grid preference. Only 'grid' | 'list' are accepted. */
    #[Session]
    public string $viewMode = 'grid';

    /** Site IDs selected via the dense list rows (bound with wire:model.live). */
    public array $selectedSites = [];

    #[Computed]
    public function availableTags()
    {
        return Tag::orderBy('name')->get();
    }

    public function updatedTagId(): void
    {
        $this->resetPage();
    }

    /** Toggle between the dense list view and the card grid view. */
    public function setViewMode(string $mode): void
    {
        if (! in_array($mode, ['grid', 'list'], true)) {
            return;
        }

        $this->viewMode = $mode;
    }

    // ── Single-site row actions (⋮ menu in <x-site-row />) ───────────────────

    /**
     * Open wp-admin for a single site using the connector's one-time login URL
     * (mirrors WithWpAdminLogin::openWpAdmin, but scoped by site id).
     */
    public function openWpAdmin(int $siteId): void
    {
        /** @var Site $site */
        $site = Site::findOrFail($siteId);
        $this->authorize('update', $site);

        try {
            $api = app(WordPressApiServiceFactory::class)->make($site);
            $username = $site->wpAdminUser?->username;
            $result = $api->getLoginUrl($username);

            if (! empty($result['login_url'])) {
                $this->js("window.open('".addslashes($result['login_url'])."', '_blank')");

                return;
            }

            $this->dispatch('notify', type: 'error', message: 'Could not generate login URL. No URL returned.');
        } catch (\Exception $e) {
            $this->dispatch('notify', type: 'error', message: 'Could not generate login URL: '.$e->getMessage());
        }
    }

    /** Queue a full manual backup for a single site. */
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

    /** Queue an on-demand uptime check for a single site. */
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

            return;
        }

        $this->dispatch('notify', type: 'warning', message: "{$site->name} has no uptime monitor configured.");
    }

    // ── Bulk toolbar actions (<x-toolbar />) ─────────────────────────────────
    // bulkBackup() and bulkCheckUptime() are provided by WithBulkSiteActions.

    /**
     * TODO: no per-site "update everything" job exists — safe updates are
     * created per plugin/theme via SafeUpdateService (see WithPluginManagement).
     * Surface a toast instead of failing until a bulk-update flow is designed.
     */
    public function bulkUpdate(): void
    {
        $this->dispatch('notify', type: 'info', message: 'Actualizarea în masă nu este disponibilă încă.');
    }

    /**
     * TODO: ApplyPlanToSite requires a chosen MaintenancePlan; the toolbar has
     * no plan picker yet, so this cannot dispatch a real job.
     */
    public function bulkApplyPlan(): void
    {
        $this->dispatch('notify', type: 'info', message: 'Aplicarea planului în masă nu este disponibilă încă.');
    }

    /**
     * TODO: no "presets" job/model exists in the codebase yet (SPEC §14.5).
     */
    public function bulkApplyPresets(): void
    {
        $this->dispatch('notify', type: 'info', message: 'Aplicarea presetărilor nu este disponibilă încă.');
    }

    /**
     * TODO: no cache-purge job exists on the connector yet.
     */
    public function bulkPurgeCache(): void
    {
        $this->dispatch('notify', type: 'info', message: 'Golirea cache-ului în masă nu este disponibilă încă.');
    }

    /** Clear the current selection (bound by the toolbar's "deselectează tot"). */
    public function clearSelection(): void
    {
        $this->selectedSites = [];
    }

    /** Alias kept for callers referring to deselectAll(). */
    public function deselectAll(): void
    {
        $this->clearSelection();
    }

    public function render()
    {
        $user = auth()->user();

        $sites = Site::query()
            ->visibleTo($user)
            ->when($this->tagId, fn ($q) => $q->whereHas('tags', fn ($tq) => $tq->where('tags.id', $this->tagId)))
            ->when($this->search, function ($q) {
                $escaped = '%'.$this->escapeLike($this->search).'%';
                $q->where(function ($q) use ($escaped) {
                    $q->where('name', 'ilike', $escaped)
                        ->orWhere('url', 'ilike', $escaped);
                });
            })
            ->when($this->filter !== 'all', function ($q) {
                return match ($this->filter) {
                    'healthy' => $q->where('health_score', '>=', HealthLevel::HEALTHY_THRESHOLD)->where('is_up', true),
                    'warning' => $q->where('health_score', '>=', HealthLevel::WARNING_THRESHOLD)->where('health_score', '<', HealthLevel::HEALTHY_THRESHOLD)->where('is_up', true),
                    'critical' => $q->where(function ($q) {
                        $q->where('health_score', '<', HealthLevel::WARNING_THRESHOLD)->orWhere('is_up', false);
                    }),
                    default => $q,
                };
            })
            ->with('client', 'uptimeMonitor', 'backupConfig', 'performanceMonitor', 'siteStatus', 'analyticsConnection', 'searchConsoleConnection', 'tags')
            ->withCount(['reportSchedules', 'siteUsers', 'sitePlugins'])
            ->paginate((int) app(SettingsService::class)->get('sites_per_page', 16));

        return view('livewire.sites.sites-list', compact('sites'))
            ->layout('components.layouts.app', ['title' => 'Sites']);
    }
}
