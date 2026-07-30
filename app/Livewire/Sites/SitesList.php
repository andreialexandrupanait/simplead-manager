<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Enums\HealthLevel;
use App\Jobs\ApplySitePresetJob;
use App\Jobs\CheckUptime;
use App\Jobs\CreateBackup;
use App\Jobs\QueueSiteSafeUpdatesJob;
use App\Livewire\Traits\WithBulkSiteActions;
use App\Livewire\Traits\WithRateLimiting;
use App\Livewire\Traits\WithTableFilters;
use App\Models\Site;
use App\Models\Tag;
use App\Operations\OperationRunner;
use App\Operations\Operations\PurgeCloudflareCacheOperation;
use App\Services\CanaryRolloutService;
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

    /** SPEC §4.4 primary tab: all | updates | alerts | plans. */
    #[Url]
    public string $tab = 'all';

    /** SPEC §4.4 grouping: none | client. */
    #[Url]
    public string $groupBy = 'none';

    public function updatedTab(): void
    {
        $this->resetPage();
    }

    public function toggleGroupByClient(): void
    {
        $this->groupBy = $this->groupBy === 'client' ? 'none' : 'client';
        $this->resetPage();
    }

    #[Computed]
    public function availableTags()
    {
        return Tag::orderBy('name')->get();
    }

    /**
     * SPEC §4.5 — the three-band Panou (no charts, no decorative widgets):
     *   1. who needs attention, grouped by client, ordered by severity
     *   2. what awaits approval
     *   3. the rest, collapsed to one line (a count)
     *
     * @return array{attention: array<int, array<string,mixed>>, awaiting: int, resting: int}
     */
    #[Computed]
    public function panou(): array
    {
        $user = auth()->user();

        $sites = Site::query()
            ->visibleTo($user)
            ->with('client')
            ->get();

        $attention = [];
        $resting = 0;

        foreach ($sites as $site) {
            $isDown = $site->is_up === false;
            $isDisconnected = $site->is_connected === false;
            $criticalHealth = $site->health_score !== null
                && (int) $site->health_score < HealthLevel::WARNING_THRESHOLD;

            if (! $isDown && ! $isDisconnected && ! $criticalHealth) {
                $resting++;

                continue;
            }

            // Severity: down/disconnected = critical (0), poor-health-only = warning (1).
            $severity = ($isDown || $isDisconnected) ? 0 : 1;
            $reasons = [];
            if ($isDown) {
                $reasons[] = __('down');
            }
            if ($isDisconnected) {
                $reasons[] = __('disconnected');
            }
            if ($criticalHealth && ! $isDown && ! $isDisconnected) {
                $reasons[] = __('health :n', ['n' => (int) $site->health_score]);
            }

            $clientName = $site->client?->name ?? __('No client');
            $attention[$clientName]['client'] = $clientName;
            $attention[$clientName]['severity'] = min($attention[$clientName]['severity'] ?? 9, $severity);
            $attention[$clientName]['sites'][] = [
                'id' => $site->id,
                'name' => $site->name,
                'severity' => $severity,
                'reasons' => implode(' · ', $reasons),
                'url' => route('sites.overview', $site),
            ];
        }

        // Sort clients by their worst severity, and sites within each client by severity.
        foreach ($attention as &$group) {
            usort($group['sites'], fn ($a, $b) => $a['severity'] <=> $b['severity']);
        }
        unset($group);
        uasort($attention, fn ($a, $b) => $a['severity'] <=> $b['severity']);

        return [
            'attention' => array_values($attention),
            'awaiting' => $this->awaitingApprovalCount(),
            'resting' => $resting,
        ];
    }

    /** SPEC §4.4 tab counters — sites with updates / with an active alert. */
    #[Computed]
    public function tabCounts(): array
    {
        $user = auth()->user();
        $warning = HealthLevel::WARNING_THRESHOLD;

        return [
            'updates' => Site::query()->visibleTo($user)
                ->whereHas('sitePlugins', fn ($q) => $q->where('has_update', true))->count(),
            'alerts' => Site::query()->visibleTo($user)
                ->where(fn ($q) => $q->where('is_up', false)->orWhere('is_connected', false)
                    ->orWhere('health_score', '<', $warning))->count(),
        ];
    }

    /** Updates held for a batch approval decision (SPEC §4.5 band 2 / §2 principle 4). */
    private function awaitingApprovalCount(): int
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('safe_updates', 'approval_required')) {
            return 0;
        }

        return \App\Models\SafeUpdate::query()
            ->where('approval_required', true)
            ->whereHas('site', fn ($q) => $q->visibleTo(auth()->user()))
            ->count();
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
     * SPEC §2 (bulk-first) / §4.4 — fleet bulk update. Fans out one
     * QueueSiteSafeUpdatesJob per selected site; each site then queues a SAFE
     * update (backup → update → health-check → auto-rollback) for every pending
     * plugin/theme. Sites that are disconnected or do not have safe updates
     * enabled are skipped, so a one-click fleet action never runs an unprotected
     * inline update.
     */
    public function bulkUpdate(): void
    {
        abort_unless(auth()->user()->canManageSites(), 403);

        if (! $this->rateLimit('bulk-update-fleet', auth()->id(), 3, 3600)) {
            $this->dispatch('notify', type: 'error', message: __('Too many bulk updates. Please wait a moment.'));

            return;
        }

        $sites = $this->scopedSiteQuery($this->selectedSites)->get();
        if ($sites->isEmpty()) {
            $this->dispatch('notify', type: 'warning', message: __('No sites selected.'));

            return;
        }

        $eligible = $sites->filter(fn (Site $site): bool => $site->is_connected && $site->safe_updates_enabled);
        $skipped = $sites->count() - $eligible->count();

        $this->selectedSites = [];

        // SPEC §7.2 Rule 1 — with smart rules on, a fleet update is staged: two
        // canaries now, the rest only after they survive 30 minutes and a smoke
        // check. With the flag off this is the same immediate fan-out as before.
        $rollout = app(CanaryRolloutService::class);

        if ($rollout->enabled() && $eligible->count() > CanaryRolloutService::CANARY_COUNT) {
            $groups = $rollout->start($eligible, auth()->id());

            $this->dispatch(
                'notify',
                type: 'success',
                message: __('Staged rollout started: :canaries canary site(s) now, :deferred more after a :minutes-minute smoke check.', [
                    'canaries' => count($groups['canaries']),
                    'deferred' => count($groups['deferred']),
                    'minutes' => CanaryRolloutService::OBSERVATION_MINUTES,
                ]),
            );

            return;
        }

        $queued = 0;
        foreach ($eligible as $site) {
            QueueSiteSafeUpdatesJob::dispatch($site, auth()->id());
            $queued++;
        }

        $message = trans_choice(
            'Safe update queued for :count site.|Safe updates queued for :count sites.',
            $queued,
            ['count' => $queued],
        );
        if ($skipped > 0) {
            $message .= ' '.__(':n skipped (disconnected or safe updates off).', ['n' => $skipped]);
        }

        $this->dispatch(
            'notify',
            type: $queued === 0 ? 'warning' : 'success',
            message: $message,
        );
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
     * SPEC §13 — apply the curated "Standard SimpleAD" preset package to the
     * selected sites: fans out one ApplySitePresetJob per connected site (the
     * auto group everywhere, the Woo group only where WooCommerce is detected).
     * Per-site deviations are preserved — a later manual tweak still wins.
     */
    public function bulkApplyPresets(): void
    {
        abort_unless(auth()->user()->canManageSites(), 403);

        if (! $this->rateLimit('bulk-presets-fleet', auth()->id(), 3, 3600)) {
            $this->dispatch('notify', type: 'error', message: __('Too many bulk actions. Please wait a moment.'));

            return;
        }

        $sites = $this->scopedSiteQuery($this->selectedSites)->get();
        if ($sites->isEmpty()) {
            $this->dispatch('notify', type: 'warning', message: __('No sites selected.'));

            return;
        }

        $queued = 0;
        $skipped = 0;
        foreach ($sites as $site) {
            if ($site->is_connected) {
                ApplySitePresetJob::dispatch($site, auth()->id());
                $queued++;
            } else {
                $skipped++;
            }
        }

        $this->selectedSites = [];

        $message = trans_choice(
            'Standard SimpleAD preset queued for :count site.|Standard SimpleAD preset queued for :count sites.',
            $queued,
            ['count' => $queued],
        );
        if ($skipped > 0) {
            $message .= ' '.__(':n skipped (not connected).', ['n' => $skipped]);
        }

        $this->dispatch('notify', type: $queued === 0 ? 'warning' : 'success', message: $message);
    }

    /**
     * Purge each selected site's Cloudflare edge cache via the shared operations
     * engine (Faza 4). Sites without an active Cloudflare zone are skipped inside
     * the operation, so the fleet-wide selection can be run as-is.
     */
    public function bulkPurgeCache(): void
    {
        abort_unless(auth()->user()->canManageSites(), 403);

        $sites = $this->scopedSiteQuery($this->selectedSites)
            ->with('siteCloudflare.cloudflareConnection')
            ->get();

        if ($sites->isEmpty()) {
            $this->dispatch('notify', type: 'warning', message: 'Niciun site selectat.');

            return;
        }

        $handle = app(OperationRunner::class)->run(
            app(PurgeCloudflareCacheOperation::class),
            $sites,
            [],
            auth()->id(),
        );

        $count = $sites->count();
        $this->selectedSites = [];
        $this->dispatch('notify', type: 'success', message: "Golire cache pusă în coadă pentru {$count} site-uri (run {$handle->runId}).");
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
            ->when($this->tab !== 'all', function ($q) {
                // SPEC §4.4 primary tabs (orthogonal to the health filter).
                return match ($this->tab) {
                    'updates' => $q->whereHas('sitePlugins', fn ($p) => $p->where('has_update', true)),
                    'alerts' => $q->where(fn ($aq) => $aq
                        ->where('is_up', false)
                        ->orWhere('is_connected', false)
                        ->orWhere('health_score', '<', HealthLevel::WARNING_THRESHOLD)),
                    default => $q, // 'plans' is a grouping, not a row filter
                };
            })
            ->with('client', 'uptimeMonitor', 'backupConfig', 'performanceMonitor', 'siteStatus', 'analyticsConnection', 'searchConsoleConnection', 'tags')
            ->withCount(['reportSchedules', 'siteUsers', 'sitePlugins'])
            // SPEC §4.4 — group by client: order by client name so rows are
            // contiguous per client and the view can emit a header per group.
            ->when($this->groupBy === 'client', fn ($q) => $q->orderBy(
                \App\Models\Client::select('name')->whereColumn('clients.id', 'sites.client_id')
            ))
            ->paginate((int) app(SettingsService::class)->get('sites_per_page', 16));

        return view('livewire.sites.sites-list', compact('sites'))
            ->layout('components.layouts.app', ['title' => 'Sites']);
    }
}
