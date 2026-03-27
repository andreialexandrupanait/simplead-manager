<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Jobs\CreateBackup;
use App\Jobs\SyncWordPressSite;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteTheme;
use App\Models\UpdateLog;
use App\Services\PluginManagerService;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SitePlugins extends Component
{
    use WithJobTracking, WithSiteAuthorization;

    public Site $site;

    public bool $embedded = false;

    public string $tab = 'plugins';

    public string $filter = 'all';

    public string $search = '';

    // Per-item update results
    public array $updateResults = [];

    // Delete confirmation state
    public ?int $confirmingDeleteId = null;

    public ?string $confirmingDeleteName = null;

    public ?int $confirmingDeleteThemeId = null;

    public ?string $confirmingDeleteThemeName = null;

    public array $confirmingDeleteThemeChildren = [];

    // Detail modal
    public ?array $detailItem = null;

    protected function jobTrackingKeys(): array
    {
        return [
            'sync' => 'sync-wp-'.$this->site->id,
            'backup' => 'backup-'.$this->site->id,
        ];
    }

    public function mount(Site $site, bool $embedded = false): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->embedded = $embedded;
        $this->initJobTracking();

        // In embedded mode, show all plugins (no auto-filter)

    }

    #[Computed]
    public function plugins()
    {
        $query = $this->site->sitePlugins();

        if ($this->filter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->filter === 'inactive') {
            $query->where('is_active', false);
        } elseif ($this->filter === 'updates') {
            $query->where('has_update', true);
        }

        if ($this->search) {
            $escaped = $this->escapeLike($this->search);
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'ilike', '%'.$escaped.'%')
                    ->orWhere('slug', 'ilike', '%'.$escaped.'%')
                    ->orWhere('author', 'ilike', '%'.$escaped.'%');
            });
        }

        return $query->orderBy('name')->get();
    }

    #[Computed]
    public function themes()
    {
        $query = $this->site->siteThemes();

        if ($this->filter === 'active') {
            $query->where('is_active', true);
        } elseif ($this->filter === 'updates') {
            $query->where('has_update', true);
        }

        if ($this->search) {
            $escaped = $this->escapeLike($this->search);
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'ilike', '%'.$escaped.'%')
                    ->orWhere('slug', 'ilike', '%'.$escaped.'%')
                    ->orWhere('author', 'ilike', '%'.$escaped.'%');
            });
        }

        return $query->orderBy('name')->get();
    }

    #[Computed]
    public function users()
    {
        $query = $this->site->siteUsers();

        if ($this->search) {
            $escaped = $this->escapeLike($this->search);
            $query->where(function ($q) use ($escaped) {
                $q->where('username', 'ilike', '%'.$escaped.'%')
                    ->orWhere('display_name', 'ilike', '%'.$escaped.'%')
                    ->orWhere('email', 'ilike', '%'.$escaped.'%')
                    ->orWhere('role', 'ilike', '%'.$escaped.'%');
            });
        }

        return $query->orderBy('display_name')->get();
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $value);
    }

    #[Computed]
    public function pluginCounts()
    {
        /** @var \stdClass $counts */
        $counts = $this->site->sitePlugins()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active = false THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN has_update = true THEN 1 ELSE 0 END) as updates
            ')
            ->first();

        return [
            'total' => (int) $counts->total,
            'active' => (int) $counts->active,
            'inactive' => (int) $counts->inactive,
            'updates' => (int) $counts->updates,
        ];
    }

    #[Computed]
    public function themeCounts()
    {
        /** @var \stdClass $counts */
        $counts = $this->site->siteThemes()
            ->selectRaw('
                COUNT(*) as total,
                SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN has_update = true THEN 1 ELSE 0 END) as updates
            ')
            ->first();

        return [
            'total' => (int) $counts->total,
            'active' => (int) $counts->active,
            'updates' => (int) $counts->updates,
        ];
    }

    #[Computed]
    public function userCount()
    {
        return $this->site->siteUsers()->count();
    }

    #[Computed]
    public function updateHistory()
    {
        $query = UpdateLog::where('site_id', $this->site->id);

        if ($this->filter !== 'all') {
            $query->where('type', $this->filter === 'plugins' ? 'plugin' : 'theme');
        }

        if ($this->search) {
            $escaped = $this->escapeLike($this->search);
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'ilike', '%'.$escaped.'%')
                    ->orWhere('slug', 'ilike', '%'.$escaped.'%');
            });
        }

        return $query->with('user')->orderByDesc('performed_at')->limit(100)->get();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->filter = 'all';
        $this->search = '';
        $this->dispatch('bulk-selection-reset');
        unset($this->plugins, $this->themes, $this->users, $this->updateHistory);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        $this->dispatch('bulk-selection-reset');
        unset($this->plugins, $this->themes, $this->updateHistory);
    }

    // ── Fix 1: Update methods with per-item results ──

    public function updatePlugin(int $pluginId): void
    {
        $result = $this->updateSinglePlugin($pluginId);
        $this->updateResults['plugin_'.$pluginId] = $result;
    }

    public function updateTheme(int $themeId): void
    {
        $result = $this->updateSingleTheme($themeId);
        $this->updateResults['theme_'.$themeId] = $result;
    }

    public function updateSinglePlugin(int $pluginId): array
    {
        /** @var SitePlugin $plugin */
        $plugin = $this->site->sitePlugins()->findOrFail($pluginId);
        $result = $this->performUpdate('plugin', $plugin->file, $plugin->name, $plugin->slug, $plugin->version, $plugin->update_version);

        if ($result['success']) {
            $plugin->update([
                'version' => $result['version'] ?? $plugin->update_version,
                'has_update' => false,
                'update_version' => null,
            ]);
            $this->site->decrement('pending_updates_count');
        }

        unset($this->plugins, $this->pluginCounts);

        return $result;
    }

    public function updateSingleTheme(int $themeId): array
    {
        /** @var SiteTheme $theme */
        $theme = $this->site->siteThemes()->findOrFail($themeId);
        $result = $this->performUpdate('theme', $theme->slug, $theme->name, $theme->slug, $theme->version, $theme->update_version);

        if ($result['success']) {
            $theme->update([
                'version' => $result['version'] ?? $theme->update_version,
                'has_update' => false,
                'update_version' => null,
            ]);
            $this->site->decrement('pending_updates_count');
        }

        unset($this->themes, $this->themeCounts);

        return $result;
    }

    private function performUpdate(string $type, string $identifier, string $name, string $slug, ?string $currentVersion, ?string $updateVersion): array
    {
        $result = app(PluginManagerService::class)->performUpdate($this->site, $type, $identifier, $name, $slug, $currentVersion, $updateVersion);

        if (! $result['success']) {
            $this->dispatch('notify', type: 'error', message: "Update failed for {$name}: ".$result['message']);
        }

        return $result;
    }

    public function getUpdatablePluginIds(): array
    {
        return $this->site->sitePlugins()->where('has_update', true)->pluck('id')->toArray();
    }

    public function getUpdatableThemeIds(): array
    {
        return $this->site->siteThemes()->where('has_update', true)->pluck('id')->toArray();
    }

    public function clearResult(string $key): void
    {
        unset($this->updateResults[$key]);
    }

    // ── Fix 2: Activate / Deactivate / Delete actions ──

    public function activatePlugin(int $id): void
    {
        $result = app(PluginManagerService::class)->activatePlugin($this->site, $id);
        $this->updateResults['plugin_'.$id] = $result + ['version' => null];
        $this->dispatch('notify', type: $result['success'] ? 'success' : 'error', message: $result['message']);
        unset($this->plugins, $this->pluginCounts);
    }

    public function deactivatePlugin(int $id): void
    {
        $result = app(PluginManagerService::class)->deactivatePlugin($this->site, $id);
        $this->updateResults['plugin_'.$id] = $result + ['version' => null];
        $this->dispatch('notify', type: $result['success'] ? 'success' : 'error', message: $result['message']);
        unset($this->plugins, $this->pluginCounts);
    }

    public function confirmDeletePlugin(int $id): void
    {
        /** @var SitePlugin $plugin */
        $plugin = $this->site->sitePlugins()->findOrFail($id);
        $this->confirmingDeleteId = $id;
        $this->confirmingDeleteName = $plugin->name;
        $this->dispatch('open-modal-confirm-delete-plugin');
    }

    public function deletePlugin(): void
    {
        if (! $this->confirmingDeleteId) {
            return;
        }

        $result = app(PluginManagerService::class)->deletePlugin($this->site, $this->confirmingDeleteId);

        if ($result['success']) {
            session()->flash('update-success', $result['message']);
        } else {
            session()->flash('update-error', $result['message']);
        }

        $this->confirmingDeleteId = null;
        $this->confirmingDeleteName = null;
        $this->dispatch('close-modal-confirm-delete-plugin');
        unset($this->plugins, $this->pluginCounts);
    }

    public function activateTheme(int $id): void
    {
        $result = app(PluginManagerService::class)->activateTheme($this->site, $id);
        $this->updateResults['theme_'.$id] = $result + ['version' => null];

        if (! $result['success']) {
            $this->dispatch('notify', type: 'error', message: $result['message']);
        }

        unset($this->themes, $this->themeCounts);
    }

    public function confirmDeleteTheme(int $id): void
    {
        /** @var SiteTheme $theme */
        $theme = $this->site->siteThemes()->findOrFail($id);
        $this->confirmingDeleteThemeId = $id;
        $this->confirmingDeleteThemeName = $theme->name;

        // Check if any child themes depend on this theme
        $childThemes = $this->site->siteThemes()
            ->where('parent_theme', $theme->slug)
            ->pluck('name')
            ->toArray();

        $this->confirmingDeleteThemeChildren = $childThemes;
        $this->dispatch('open-modal-confirm-delete-theme');
    }

    public function deleteThemeById(int $id): void
    {
        $result = app(PluginManagerService::class)->deleteTheme($this->site, $id);
        $this->dispatch('notify', type: $result['success'] ? 'success' : 'error', message: $result['message']);
        unset($this->themes, $this->themeCounts);
    }

    public function deleteTheme(): void
    {
        if (! $this->confirmingDeleteThemeId) {
            return;
        }

        $result = app(PluginManagerService::class)->deleteTheme($this->site, $this->confirmingDeleteThemeId);

        if ($result['success']) {
            session()->flash('update-success', $result['message']);
        } else {
            session()->flash('update-error', $result['message']);
        }

        $this->confirmingDeleteThemeId = null;
        $this->confirmingDeleteThemeName = null;
        $this->confirmingDeleteThemeChildren = [];
        $this->dispatch('close-modal-confirm-delete-theme');
        unset($this->themes, $this->themeCounts);
    }

    // ── Bulk Actions ──

    public function deletePluginDirect(int $id): array
    {
        $result = app(PluginManagerService::class)->deletePlugin($this->site, $id);

        if (! $result['success']) {
            $this->updateResults['plugin_'.$id] = ['success' => false, 'message' => $result['message'], 'version' => null];
        }

        unset($this->plugins, $this->pluginCounts);

        return $result;
    }

    public function deleteThemeDirect(int $id): array
    {
        $result = app(PluginManagerService::class)->deleteTheme($this->site, $id);

        if (! $result['success']) {
            $this->updateResults['theme_'.$id] = ['success' => false, 'message' => $result['message'], 'version' => null];
        }

        unset($this->themes, $this->themeCounts);

        return $result;
    }

    public function bulkUpdatePlugins(array $ids): array
    {
        $this->runPreUpdateBackup();
        $result = app(PluginManagerService::class)->bulkUpdatePlugins($this->site, $ids);
        $this->updateResults = array_merge($this->updateResults, $result['results']);
        unset($this->plugins, $this->pluginCounts);

        return ['success' => $result['success'], 'failed' => $result['failed']];
    }

    public function bulkUpdateThemes(array $ids): array
    {
        $this->runPreUpdateBackup();
        $result = app(PluginManagerService::class)->bulkUpdateThemes($this->site, $ids);
        $this->updateResults = array_merge($this->updateResults, $result['results']);
        unset($this->themes, $this->themeCounts);

        return ['success' => $result['success'], 'failed' => $result['failed']];
    }

    public function getFilteredPluginIds(): array
    {
        return $this->plugins->pluck('id')->toArray();
    }

    public function getFilteredThemeIds(): array
    {
        return $this->themes->pluck('id')->toArray();
    }

    public function syncNow(): void
    {
        $rateLimitKey = "sync:{$this->site->id}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 10, fn () => true, 3600)) {
            session()->flash('update-error', 'Too many sync requests. Please wait before trying again.');

            return;
        }

        $this->dispatchTrackedJob('sync', new SyncWordPressSite($this->site), 'Syncing site data...');
    }

    // ── Quick Actions ──

    public function openWpAdmin(): void
    {
        try {
            $api = app(WordPressApiServiceFactory::class)->make($this->site);
            $result = $api->getLoginUrl();

            if (! empty($result['login_url'])) {
                $this->js("window.open('".addslashes($result['login_url'])."', '_blank')");

                return;
            }

            session()->flash('update-error', 'Could not generate login URL. No URL returned.');
        } catch (\Exception $e) {
            session()->flash('update-error', 'Could not generate login URL: '.$e->getMessage());
        }
    }

    public function quickBackup(): void
    {
        $rateLimitKey = "backup:{$this->site->id}:".auth()->id();
        if (! RateLimiter::attempt($rateLimitKey, 5, fn () => true, 3600)) {
            session()->flash('update-error', 'Too many backup requests. Please wait before trying again.');

            return;
        }

        $this->dispatchTrackedJob('backup', new CreateBackup($this->site, 'full', 'manual'), 'Creating backup...');
    }

    // ── Core Update ──

    public function updateCore(): void
    {
        $this->runPreUpdateBackup();
        $result = app(PluginManagerService::class)->updateCore($this->site);

        if ($result['success']) {
            session()->flash('update-success', $result['message']);
        } else {
            session()->flash('update-error', $result['message']);
        }
    }

    private function runPreUpdateBackup(): void
    {
        $config = $this->site->backupConfig;
        if ($config?->backup_before_updates) {
            CreateBackup::dispatch($this->site, 'database', 'pre_update', $config->storage_destination_id);
        }
    }

    // ── Auto-Update Toggle ──

    public function toggleAutoUpdate(string $type, int $id): void
    {
        if ($type === 'plugin') {
            /** @var SitePlugin $item */
            $item = $this->site->sitePlugins()->findOrFail($id);
        } else {
            /** @var SiteTheme $item */
            $item = $this->site->siteThemes()->findOrFail($id);
        }

        $item->update(['auto_update' => ! $item->auto_update]);

        $this->dispatch('notify', type: 'success', message: ($item->auto_update ? 'Enabled' : 'Disabled')." auto-updates for {$item->name}.");
        unset($this->plugins, $this->themes);
    }

    // ── Detail Modal ──

    public function showDetail(string $type, int $id): void
    {
        if ($type === 'plugin') {
            /** @var SitePlugin $item */
            $item = $this->site->sitePlugins()->findOrFail($id);
        } else {
            /** @var SiteTheme $item */
            $item = $this->site->siteThemes()->findOrFail($id);
        }

        $this->detailItem = [
            'type' => $type,
            'name' => $item->name,
            'slug' => $item->slug,
            'version' => $item->version,
            'author' => $item->author,
            'description' => $item->description,
            'url' => $item->url,
            'is_active' => $item->is_active,
            'auto_update' => $item->auto_update,
            'has_update' => $item->has_update,
            'update_version' => $item->update_version,
            'is_abandoned' => $item->is_abandoned ?? false,
            'is_closed' => $item->is_closed ?? false,
            'closed_reason' => $item->closed_reason ?? null,
            'wp_org_last_updated' => $item->wp_org_last_updated?->format('M j, Y'),
        ];

        $this->dispatch('open-modal-plugin-detail');
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        unset($this->plugins, $this->themes, $this->users, $this->pluginCounts, $this->themeCounts, $this->userCount, $this->updateHistory);
        $this->site->refresh();
    }

    public function render()
    {
        $view = view('livewire.sites.detail.site-plugins');

        if (! $this->embedded) {
            $view->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Plugins & Themes',
            ]);
        }

        return $view;
    }
}
