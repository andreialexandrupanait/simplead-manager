<?php

declare(strict_types=1);

namespace App\Livewire\Updates;

use App\Jobs\CreateBackup;
use App\Jobs\RunSafeUpdate;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteTheme;
use App\Services\PluginManagerService;
use App\Services\SafeUpdateService;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

class UpdatesOverview extends Component
{
    use WithSiteAuthorization;

    public string $filter = 'all';

    public string $search = '';

    public string $groupBy = 'site';

    public array $updateResults = [];

    #[Computed]
    public function stats(): array
    {
        $pluginUpdates = SitePlugin::where('has_update', true)
            ->whereHas('site', fn ($q) => $q->where('is_connected', true))
            ->count();

        $themeUpdates = SiteTheme::where('has_update', true)
            ->whereHas('site', fn ($q) => $q->where('is_connected', true))
            ->count();

        $sitesWithUpdates = Site::where('is_connected', true)
            ->where('pending_updates_count', '>', 0)
            ->count();

        return [
            'total' => $pluginUpdates + $themeUpdates,
            'plugins' => $pluginUpdates,
            'themes' => $themeUpdates,
            'sites' => $sitesWithUpdates,
        ];
    }

    #[Computed]
    public function updates(): array
    {
        $items = collect();

        if ($this->filter !== 'themes') {
            $plugins = SitePlugin::where('has_update', true)
                ->whereHas('site', fn ($q) => $q->where('is_connected', true))
                ->with('site')
                ->when($this->search, function ($q) {
                    $search = '%'.$this->escapeLike($this->search).'%';
                    $q->where(function ($sq) use ($search) {
                        $sq->where('name', 'ilike', $search)
                            ->orWhere('slug', 'ilike', $search)
                            ->orWhereHas('site', fn ($s) => $s->where('name', 'ilike', $search)->orWhere('url', 'ilike', $search));
                    });
                })
                ->get()
                ->map(fn (SitePlugin $p) => [
                    'id' => $p->id,
                    'type' => 'plugin',
                    'name' => $p->name,
                    'slug' => $p->slug,
                    'file' => $p->file,
                    'version' => $p->version,
                    'update_version' => $p->update_version,
                    'site_id' => $p->site_id,
                    'site_name' => $p->site?->name ?? '—',
                    'site_url' => $p->site?->url ?? '',
                    'site_sort_order' => $p->site?->sort_order ?? 0,
                    'is_active' => $p->is_active,
                    'auto_update' => $p->auto_update,
                ]);

            $items = $items->concat($plugins);
        }

        if ($this->filter !== 'plugins') {
            $themes = SiteTheme::where('has_update', true)
                ->whereHas('site', fn ($q) => $q->where('is_connected', true))
                ->with('site')
                ->when($this->search, function ($q) {
                    $search = '%'.$this->escapeLike($this->search).'%';
                    $q->where(function ($sq) use ($search) {
                        $sq->where('name', 'ilike', $search)
                            ->orWhere('slug', 'ilike', $search)
                            ->orWhereHas('site', fn ($s) => $s->where('name', 'ilike', $search)->orWhere('url', 'ilike', $search));
                    });
                })
                ->get()
                ->map(fn (SiteTheme $t) => [
                    'id' => $t->id,
                    'type' => 'theme',
                    'name' => $t->name,
                    'slug' => $t->slug,
                    'file' => $t->slug,
                    'version' => $t->version,
                    'update_version' => $t->update_version,
                    'site_id' => $t->site_id,
                    'site_name' => $t->site?->name ?? '—',
                    'site_url' => $t->site?->url ?? '',
                    'site_sort_order' => $t->site?->sort_order ?? 0,
                    'is_active' => $t->is_active,
                    'auto_update' => $t->auto_update,
                ]);

            $items = $items->concat($themes);
        }

        if ($this->groupBy === 'item') {
            return $items->sortBy('name')->groupBy('slug')->map(fn ($group) => [
                'label' => $group->first()['name'],
                'type' => $group->first()['type'],
                'update_version' => $group->first()['update_version'],
                'items' => $group->sortBy('site_sort_order')->values()->all(),
            ])->values()->all();
        }

        return $items->sortBy('site_sort_order')->groupBy('site_id')->map(fn ($group) => [
            'label' => $group->first()['site_name'],
            'site_id' => $group->first()['site_id'],
            'items' => $group->sortBy('name')->values()->all(),
        ])->values()->all();
    }

    public function updateSingle(string $type, int $id): void
    {
        if ($type === 'plugin') {
            $item = SitePlugin::with('site')->findOrFail($id);
        } else {
            $item = SiteTheme::with('site')->findOrFail($id);
        }

        $site = $item->site;

        if (! $site) {
            $this->dispatch('notify', type: 'error', message: 'Site not found.');

            return;
        }

        $this->authorizeSiteModification($site);

        if (! $site->is_connected) {
            $this->dispatch('notify', type: 'error', message: 'Site is not connected.');

            return;
        }

        $key = "{$type}_{$id}";

        // Sites that opted into safe updates must go through the queued safety
        // pipeline (pre-update backup → update → health check → visual
        // regression → auto-rollback) — the global page used to bypass it
        // entirely and update with no safety net (P0-08).
        if ($site->safe_updates_enabled) {
            $this->queueSafeUpdateForItem($site, $type, $item);
            $this->updateResults[$key] = [
                'success' => true,
                'queued' => true,
                'message' => "Safe update queued for {$item->name}.",
                'version' => null,
            ];
            $this->dispatch('notify', type: 'success',
                message: "Safe update queued for {$item->name} — it will back up, health-check and roll back automatically if anything breaks.");
            unset($this->updates, $this->stats);

            return;
        }

        // Non-safe sites: take the opt-in pre-update backup before touching the
        // site, then update inline (unchanged behaviour for flag-off sites).
        $this->preUpdateBackup($site);

        $identifier = $type === 'plugin' ? $item->file : $item->slug;
        $result = app(PluginManagerService::class)->performUpdate(
            $site, $type, $identifier, $item->name, $item->slug, $item->version, $item->update_version
        );

        $this->updateResults[$key] = $result;

        if ($result['success']) {
            $item->update([
                'version' => $result['version'] ?? $item->update_version,
                'has_update' => false,
                'update_version' => null,
            ]);
            if ($type === 'plugin') {
                $site->decrement('pending_updates_count');
            }
        }

        unset($this->updates, $this->stats);
    }

    public function updateAllForSite(int $siteId): void
    {
        $rateLimitKey = "bulk-update-site:{$siteId}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, 3600)) {
            $this->dispatch('notify', type: 'error', message: 'Too many requests. Please wait.');

            return;
        }

        $site = Site::findOrFail($siteId);
        $this->authorizeSiteModification($site);
        if (! $site->is_connected) {
            $this->dispatch('notify', type: 'error', message: 'Site is not connected.');

            return;
        }

        $plugins = $site->sitePlugins()->where('has_update', true)->get();
        $themes = $site->siteThemes()->where('has_update', true)->get();

        // Safe-update sites: queue every pending item through the safety pipeline
        // instead of bulk-updating inline with no backup/rollback (P0-08).
        if ($site->safe_updates_enabled) {
            $queued = 0;
            /** @var SitePlugin $plugin */
            foreach ($plugins as $plugin) {
                $this->queueSafeUpdateForItem($site, 'plugin', $plugin);
                $queued++;
            }
            /** @var SiteTheme $theme */
            foreach ($themes as $theme) {
                $this->queueSafeUpdateForItem($site, 'theme', $theme);
                $queued++;
            }

            $this->dispatch('notify', type: 'success',
                message: "{$queued} safe update(s) queued for {$site->name} — each backs up, health-checks and auto-rolls-back.");
            unset($this->updates, $this->stats);

            return;
        }

        // Non-safe sites: one opt-in pre-update backup, then inline updates.
        $this->preUpdateBackup($site);

        $service = app(PluginManagerService::class);
        $success = 0;
        $failed = 0;

        foreach ($plugins as $plugin) {
            $result = $service->performUpdate($site, 'plugin', $plugin->file, $plugin->name, $plugin->slug, $plugin->version, $plugin->update_version);
            if ($result['success']) {
                $plugin->update(['version' => $result['version'] ?? $plugin->update_version, 'has_update' => false, 'update_version' => null]);
                $site->decrement('pending_updates_count');
                $success++;
            } else {
                $failed++;
            }
        }

        foreach ($themes as $theme) {
            $result = $service->performUpdate($site, 'theme', $theme->slug, $theme->name, $theme->slug, $theme->version, $theme->update_version);
            if ($result['success']) {
                $theme->update(['version' => $result['version'] ?? $theme->update_version, 'has_update' => false, 'update_version' => null]);
                $success++;
            } else {
                $failed++;
            }
        }

        $this->dispatch('notify', type: $failed > 0 ? 'warning' : 'success', message: "{$success} updated, {$failed} failed on {$site->name}.");
        unset($this->updates, $this->stats);
    }

    public function updatePluginAcrossSites(string $slug): void
    {
        if (auth()->user()->isViewer()) {
            abort(403, 'Viewers cannot modify sites.');
        }

        $rateLimitKey = "bulk-update-plugin:{$slug}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 3, fn () => true, 3600)) {
            $this->dispatch('notify', type: 'error', message: 'Too many requests. Please wait.');

            return;
        }

        $plugins = SitePlugin::where('slug', $slug)
            ->where('has_update', true)
            ->whereHas('site', fn ($q) => $q->where('is_connected', true))
            ->with('site')
            ->get();

        if ($plugins->isEmpty()) {
            $this->dispatch('notify', type: 'warning', message: 'No connected sites have a pending update for this plugin.');

            return;
        }

        $user = auth()->user();
        $service = app(PluginManagerService::class);
        $success = 0;
        $failed = 0;
        $skipped = 0;
        $queued = 0;

        foreach ($plugins as $plugin) {
            $site = $plugin->site;
            if (! $site?->is_connected) {
                $failed++;

                continue;
            }

            // Enforce per-site access FIRST: a non-admin must not touch sites
            // outside the clients/sites assigned to them (P0-09).
            if (! $user->canAccessSite($site)) {
                $skipped++;

                continue;
            }

            // Route safe-update sites through the safety pipeline; the fleet-wide
            // action used to update every site inline with no backup (P0-08).
            if ($site->safe_updates_enabled) {
                $this->queueSafeUpdateForItem($site, 'plugin', $plugin);
                $queued++;

                continue;
            }

            // Non-safe sites: opt-in pre-update backup, then inline update.
            $this->preUpdateBackup($site);

            $result = $service->performUpdate(
                $site,
                'plugin',
                $plugin->file,
                $plugin->name,
                $plugin->slug,
                $plugin->version,
                $plugin->update_version,
            );

            if ($result['success']) {
                $plugin->update([
                    'version' => $result['version'] ?? $plugin->update_version,
                    'has_update' => false,
                    'update_version' => null,
                ]);
                $site->decrement('pending_updates_count');
                $success++;
            } else {
                $failed++;
            }
        }

        $message = "{$success} site(s) updated, {$failed} failed for \"{$slug}\".";
        if ($queued > 0) {
            $message .= " {$queued} queued as safe update(s).";
        }
        if ($skipped > 0) {
            $message .= " {$skipped} skipped (no access).";
        }

        $this->dispatch(
            'notify',
            type: ($failed > 0 || $skipped > 0) ? 'warning' : 'success',
            message: $message,
        );

        unset($this->updates, $this->stats);
    }

    public function clearResult(string $key): void
    {
        unset($this->updateResults[$key]);
    }

    /**
     * Queue a single plugin/theme through the safe-update pipeline for a site
     * that has opted into safe updates.
     */
    private function queueSafeUpdateForItem(Site $site, string $type, SitePlugin|SiteTheme $item): void
    {
        $safeUpdate = app(SafeUpdateService::class)->createSafeUpdate(
            $site,
            $type,
            $item->slug,
            $item->name,
            $item->version ?? '',
            $item->update_version ?? '',
            // Plugins address the connector by their plugin FILE; themes/core
            // fall back to the slug inside SafeUpdateService.
            $type === 'plugin' ? $item->file : null,
        );

        RunSafeUpdate::dispatch($safeUpdate, auth()->id());
    }

    /**
     * Take the opt-in pre-update DB backup for a non-safe-update site before it
     * is touched inline. Mirrors WithPluginManagement::runPreUpdateBackup so the
     * global page respects the same backup_before_updates toggle (P0-08). Sites
     * that never opted into pre-update backups keep their exact current behaviour.
     */
    private function preUpdateBackup(Site $site): void
    {
        // loadMissing avoids a lazy-load violation / N+1 when called per-site
        // inside the fleet-wide update loop.
        $config = $site->loadMissing('backupConfig')->backupConfig;
        if ($config?->backup_before_updates) {
            // Synchronous so the backup completes BEFORE the inline update runs —
            // dispatching async raced the update and could snapshot post-update
            // state, giving a useless "pre-update" restore point (P1-18).
            CreateBackup::dispatchSync($site, 'database', 'pre_update', $config->storage_destination_id);
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    public function render()
    {
        return view('livewire.updates.updates-overview')
            ->layout('components.layouts.app', ['title' => 'Updates']);
    }
}
