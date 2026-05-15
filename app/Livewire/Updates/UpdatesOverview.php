<?php

declare(strict_types=1);

namespace App\Livewire\Updates;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteTheme;
use App\Services\PluginManagerService;
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

        $identifier = $type === 'plugin' ? $item->file : $item->slug;
        $result = app(PluginManagerService::class)->performUpdate(
            $site, $type, $identifier, $item->name, $item->slug, $item->version, $item->update_version
        );

        $key = "{$type}_{$id}";
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

        $service = app(PluginManagerService::class);
        $success = 0;
        $failed = 0;

        foreach ($plugins as $plugin) {
            $site = $plugin->site;
            if (! $site?->is_connected) {
                $failed++;

                continue;
            }

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

        $this->dispatch(
            'notify',
            type: $failed > 0 ? 'warning' : 'success',
            message: "{$success} site(s) updated, {$failed} failed for \"{$slug}\".",
        );

        unset($this->updates, $this->stats);
    }

    public function clearResult(string $key): void
    {
        unset($this->updateResults[$key]);
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
