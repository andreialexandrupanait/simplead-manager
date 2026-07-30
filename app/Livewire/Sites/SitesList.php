<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Enums\HealthLevel;
use App\Jobs\CheckUptime;
use App\Jobs\CreateBackup;
use App\Livewire\Traits\WithBulkSiteActions;
use App\Livewire\Traits\WithRateLimiting;
use App\Livewire\Traits\WithSiteRowActions;
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
    use WithBulkSiteActions, WithRateLimiting, WithSiteRowActions, WithTableFilters;

    protected $listeners = ['site-deleted' => '$refresh'];

    #[Url]
    public ?int $tagId = null;

    /** Persisted list/grid preference. Only 'grid' | 'list' are accepted. */
    #[Session]
    public string $viewMode = 'grid';

    /** Site IDs selected via the dense list rows (bound with wire:model.live). */
    public array $selectedSites = [];

    /** Maintenance plan chosen in the bulk toolbar's picker (§9). */
    public ?int $bulkPlanId = null;

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

    /** Plans offered by the bulk toolbar's "apply plan" picker (§9). */
    #[Computed]
    public function availablePlans()
    {
        return \App\Models\MaintenancePlan::orderBy('name')->get(['id', 'name']);
    }

    /**
     * SPEC §4.5 — the three-band Panou (no charts, no decorative widgets):
     *   1. who needs attention, grouped by client, ordered by severity
     *   2. what awaits approval
     *   3. the rest, collapsed to one line (a count)
     *
     * @return array{attention: array<int, array<string,mixed>>, awaiting: int, resting: int}
     */
    /**
     * The five fleet numbers the landing page leads with — the same data the
     * classic dashboard shows, so the two cannot drift apart.
     */
    #[Computed]
    public function stats(): array
    {
        return app(\App\Services\DashboardService::class)->getStats();
    }

    #[Computed]
    public function trends(): array
    {
        return app(\App\Services\DashboardService::class)->getTrends();
    }

    /**
     * How many sites need a human. The list of them lives on /alerts now; this
     * is only the way in.
     */
    #[Computed]
    public function attentionCount(): int
    {
        return Site::query()->visibleTo(auth()->user())->needsAttention()->count();
    }

    /** SPEC §4.4 tab counters — sites with updates / with an active alert. */
    #[Computed]
    public function tabCounts(): array
    {
        $user = auth()->user();

        return [
            'updates' => Site::query()->visibleTo($user)
                ->whereHas('sitePlugins', fn ($q) => $q->where('has_update', true))->count(),
            // Same definition as the sidebar counter and the alerts page.
            'alerts' => Site::query()->visibleTo($user)->needsAttention()->count(),
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
     * SPEC §2 (bulk-first) / §4.4 — fleet bulk update, through the shared
     * operations engine (§6.2). Each selected site queues a SAFE update
     * (backup → update → health check → smoke check → auto-rollback) for every
     * pending plugin/theme. Sites that are disconnected or do not have safe
     * updates enabled are skipped, so a one-click fleet action never runs an
     * unprotected inline update.
     *
     * With smart rules on, the fan-out is staged behind two canaries (§7.2).
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

        // Immediate (unstaged) fan-out runs through the shared engine, so the batch
        // gets a progress tracker, a per-site result and the long-duration queue.
        $queued = $eligible->count();

        if ($queued > 0) {
            app(OperationRunner::class)->run(
                app(\App\Operations\Operations\QueueSafeUpdatesOperation::class),
                $eligible,
                [],
                auth()->id(),
            );
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
     * SPEC §6.2 / §9 — apply a maintenance plan to the selection through the
     * shared engine. The plan comes from the toolbar's picker ($bulkPlanId); the
     * operation skips any site whose plan cannot be resolved.
     */
    public function bulkApplyPlan(): void
    {
        abort_unless(auth()->user()->canManageSites(), 403);

        if (! $this->bulkPlanId) {
            $this->dispatch('notify', type: 'warning', message: __('Choose a plan first.'));

            return;
        }

        $this->runOperationOnSelection(
            'plan.apply',
            ['plan_id' => $this->bulkPlanId],
            fn (int $count): string => trans_choice(
                'Plan queued for :count site.|Plan queued for :count sites.',
                $count,
                ['count' => $count],
            ),
        );
    }

    /**
     * SPEC §13 — apply the curated "Standard SimpleAD" preset package to the
     * selected sites through the operations engine: the auto group everywhere,
     * the Woo group only where WooCommerce is detected. Per-site deviations are
     * preserved — a later manual tweak still wins.
     */
    public function bulkApplyPresets(): void
    {
        abort_unless(auth()->user()->canManageSites(), 403);

        if (! $this->rateLimit('bulk-presets-fleet', auth()->id(), 3, 3600)) {
            $this->dispatch('notify', type: 'error', message: __('Too many bulk actions. Please wait a moment.'));

            return;
        }

        // The disconnected-site gate now lives in the operation's prepare(), so a
        // site skipped here reports its reason in the batch instead of vanishing
        // from a counter.
        $this->runOperationOnSelection(
            'presets.apply',
            [],
            fn (int $count): string => trans_choice(
                'Standard SimpleAD preset queued for :count site.|Standard SimpleAD preset queued for :count sites.',
                $count,
                ['count' => $count],
            ),
        );
    }

    /**
     * Shared tail for the toolbar's engine-backed bulk actions (SPEC §6.2): scope
     * the selection, hand it to the runner, clear the selection and report.
     *
     * An operation that requires approval comes back as a pending handle — the
     * batch is persisted and nothing is dispatched until it is approved.
     *
     * @param  array<string, mixed>  $params
     * @param  callable(int): string  $message
     */
    protected function runOperationOnSelection(string $operationKey, array $params, callable $message): void
    {
        $sites = $this->scopedSiteQuery($this->selectedSites)->get();

        if ($sites->isEmpty()) {
            $this->dispatch('notify', type: 'warning', message: __('No sites selected.'));

            return;
        }

        $operation = app(\App\Operations\OperationRegistry::class)->resolve($operationKey);
        $handle = app(OperationRunner::class)->run($operation, $sites, $params, auth()->id());

        $count = $sites->count();
        $this->selectedSites = [];

        if ($handle->status === 'pending_approval') {
            $this->dispatch('notify', type: 'warning', message: __('Waiting for approval before running on :count site(s).', ['count' => $count]));

            return;
        }

        $this->dispatch('notify', type: 'success', message: $message($count));
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
                    'alerts' => $q->needsAttention(),
                    default => $q, // 'plans' is a grouping, not a row filter
                };
            })
            // The rich fleet row opens a hovercard per signal, so everything it
            // reads is eager-loaded here — otherwise it is an N+1 across every
            // row on the page. Mirrors DashboardService::getSitesOverview().
            ->with([
                'client',
                'uptimeMonitor',
                'uptimeMonitor.incidents' => fn ($q) => $q->orderByDesc('started_at')->limit(10),
                'performanceMonitor',
                'backupConfig',
                'latestCompletedBackup',
                'sitePlugins' => fn ($q) => $q->where('has_update', true),
                'siteThemes' => fn ($q) => $q->where('has_update', true),
                'analyticsConnection',
                'searchConsoleConnection',
                'reportSchedules' => fn ($q) => $q->where('is_active', true),
                'healthState',
                'siteStatus',
                'tags',
            ])
            ->withCount([
                'sitePlugins',
                'sitePlugins as plugins_with_updates_count' => fn ($q) => $q->where('has_update', true),
                'siteThemes as themes_with_updates_count' => fn ($q) => $q->where('has_update', true),
                'siteUsers',
                'backups',
                'reportSchedules',
            ])
            ->withCount(['reportSchedules', 'siteUsers', 'sitePlugins'])
            // SPEC §4.4 — group by client: order by client name so rows are
            // contiguous per client and the view can emit a header per group.
            ->when($this->groupBy === 'client', fn ($q) => $q->orderBy(
                \App\Models\Client::select('name')->whereColumn('clients.id', 'sites.client_id')
            ))
            ->paginate((int) app(SettingsService::class)->get('sites_per_page', 50));

        return view('livewire.sites.sites-list', compact('sites'))
            ->layout('components.layouts.app', ['title' => 'Sites']);
    }
}
