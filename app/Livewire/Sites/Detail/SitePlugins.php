<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\CheckAbandonedPluginsJob;
use App\Jobs\SyncWordPressSite;
use App\Livewire\Traits\WithJobTracking;
use App\Models\Site;
use App\Models\UpdateLog;
use App\Services\PluginConflictService;
use App\Services\WordPressApiService;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SitePlugins extends Component
{
    use WithJobTracking;

    private static ?bool $hasSiteUsersTable = null;
    private static ?bool $hasSitePluginConflictsTable = null;

    public Site $site;

    public string $tab = 'plugins';
    public string $filter = 'all';
    public string $search = '';

    // Fix 1: Per-item update results
    public array $updateResults = [];

    // Fix 2: Delete confirmation state
    public ?int $confirmingDeleteId = null;
    public ?int $confirmingDeleteThemeId = null;

    protected function jobTrackingKeys(): array
    {
        return [
            'sync' => 'sync-wp-' . $this->site->id,
            'abandoned' => 'abandoned-plugins-' . $this->site->id,
        ];
    }

    public function mount(Site $site): void
    {
        $this->site = $site;
        $this->initJobTracking();
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
        } elseif ($this->filter === 'abandoned') {
            $query->problematic();
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('slug', 'like', '%' . $this->search . '%')
                  ->orWhere('author', 'like', '%' . $this->search . '%');
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
            $query->where(function ($q) {
                $q->where('name', 'like', '%' . $this->search . '%')
                  ->orWhere('slug', 'like', '%' . $this->search . '%')
                  ->orWhere('author', 'like', '%' . $this->search . '%');
            });
        }

        return $query->orderBy('name')->get();
    }

    #[Computed]
    public function users()
    {
        if (!(static::$hasSiteUsersTable ??= Schema::hasTable('site_users'))) {
            return collect();
        }

        $query = $this->site->siteUsers();

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('username', 'like', '%' . $this->search . '%')
                  ->orWhere('display_name', 'like', '%' . $this->search . '%')
                  ->orWhere('email', 'like', '%' . $this->search . '%')
                  ->orWhere('role', 'like', '%' . $this->search . '%');
            });
        }

        return $query->orderBy('display_name')->get();
    }

    #[Computed]
    public function pluginCounts()
    {
        $counts = $this->site->sitePlugins()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active = false THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN has_update = true THEN 1 ELSE 0 END) as updates
            ")
            ->first();

        return [
            'total' => (int) $counts->total,
            'active' => (int) $counts->active,
            'inactive' => (int) $counts->inactive,
            'updates' => (int) $counts->updates,
            'issues' => $this->site->sitePlugins()->problematic()->count(),
        ];
    }

    #[Computed]
    public function abandonedCounts()
    {
        return [
            'abandoned' => $this->site->sitePlugins()->abandoned()->count(),
            'closed' => $this->site->sitePlugins()->closed()->count(),
        ];
    }

    #[Computed]
    public function lastAbandonedCheck()
    {
        return $this->site->sitePlugins()->max('abandoned_checked_at');
    }

    #[Computed]
    public function activeConflicts()
    {
        if (!(static::$hasSitePluginConflictsTable ??= Schema::hasTable('site_plugin_conflicts'))) {
            return collect();
        }

        return $this->site->sitePluginConflicts()
            ->where('status', 'active')
            ->with('conflict')
            ->get();
    }

    #[Computed]
    public function themeCounts()
    {
        return [
            'total' => $this->site->siteThemes()->count(),
            'active' => $this->site->siteThemes()->where('is_active', true)->count(),
            'updates' => $this->site->siteThemes()->where('has_update', true)->count(),
        ];
    }

    #[Computed]
    public function userCount()
    {
        if (!(static::$hasSiteUsersTable ??= Schema::hasTable('site_users'))) {
            return 0;
        }

        return $this->site->siteUsers()->count();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->filter = 'all';
        $this->search = '';
        unset($this->plugins, $this->themes, $this->users);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        unset($this->plugins, $this->themes);
    }

    // ── Fix 1: Update methods with per-item results ──

    public function updatePlugin(int $pluginId): void
    {
        $result = $this->updateSinglePlugin($pluginId);
        $this->updateResults['plugin_' . $pluginId] = $result;
    }

    public function updateTheme(int $themeId): void
    {
        $result = $this->updateSingleTheme($themeId);
        $this->updateResults['theme_' . $themeId] = $result;
    }

    public function updateSinglePlugin(int $pluginId): array
    {
        $plugin = $this->site->sitePlugins()->findOrFail($pluginId);
        $result = $this->performUpdate('plugin', $plugin->file, $plugin->name, $plugin->slug, $plugin->version, $plugin->update_version);
        unset($this->plugins, $this->pluginCounts);
        return $result;
    }

    public function updateSingleTheme(int $themeId): array
    {
        $theme = $this->site->siteThemes()->findOrFail($themeId);
        $result = $this->performUpdate('theme', $theme->slug, $theme->name, $theme->slug, $theme->version, $theme->update_version);
        unset($this->themes, $this->themeCounts);
        return $result;
    }

    private function performUpdate(string $type, string $identifier, string $name, string $slug, ?string $currentVersion, ?string $updateVersion): array
    {
        try {
            $api = new WordPressApiService($this->site);
            $result = $type === 'plugin'
                ? $api->updatePlugins([$identifier])
                : $api->updateThemes([$identifier]);

            $updateResult = $result['results'][0] ?? [];

            UpdateLog::create([
                'site_id' => $this->site->id,
                'user_id' => auth()->id(),
                'type' => $type,
                'name' => $name,
                'slug' => $slug,
                'from_version' => $updateResult['from_version'] ?? $currentVersion,
                'to_version' => $updateResult['to_version'] ?? $updateVersion,
                'success' => $updateResult['success'] ?? false,
                'error_message' => $updateResult['error'] ?? null,
                'performed_at' => now(),
            ]);

            SyncWordPressSite::dispatch($this->site);

            $success = $updateResult['success'] ?? false;
            return [
                'success' => $success,
                'message' => $success
                    ? "Updated to v" . ($updateResult['to_version'] ?? $updateVersion)
                    : ($updateResult['error'] ?? 'Update failed'),
                'version' => $updateResult['to_version'] ?? $updateVersion,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => "Failed: {$e->getMessage()}",
                'version' => null,
            ];
        }
    }

    public function getUpdatablePluginIds(): array
    {
        return $this->site->sitePlugins()->where('has_update', true)->pluck('id')->toArray();
    }

    public function getUpdatableThemeIds(): array
    {
        return $this->site->siteThemes()->where('has_update', true)->pluck('id')->toArray();
    }

    public function updateAllPlugins(): void
    {
        $plugins = $this->site->sitePlugins()->where('has_update', true)->get();

        if ($plugins->isEmpty()) {
            return;
        }

        try {
            $api = new WordPressApiService($this->site);
            $result = $api->updatePlugins($plugins->pluck('file')->toArray());

            foreach ($result['results'] ?? [] as $updateResult) {
                $plugin = $plugins->firstWhere('file', $updateResult['file']);
                if ($plugin) {
                    UpdateLog::create([
                        'site_id' => $this->site->id,
                        'user_id' => auth()->id(),
                        'type' => 'plugin',
                        'name' => $plugin->name,
                        'slug' => $plugin->slug,
                        'from_version' => $updateResult['from_version'] ?? $plugin->version,
                        'to_version' => $updateResult['to_version'] ?? $plugin->update_version,
                        'success' => $updateResult['success'] ?? false,
                        'error_message' => $updateResult['error'] ?? null,
                        'performed_at' => now(),
                    ]);
                }
            }

            SyncWordPressSite::dispatch($this->site);

            session()->flash('update-success', count($plugins) . ' plugin(s) update initiated.');
        } catch (\Exception $e) {
            session()->flash('update-error', "Bulk plugin update failed: {$e->getMessage()}");
        }

        unset($this->plugins, $this->pluginCounts);
    }

    public function updateAllThemes(): void
    {
        $themes = $this->site->siteThemes()->where('has_update', true)->get();

        if ($themes->isEmpty()) {
            return;
        }

        try {
            $api = new WordPressApiService($this->site);
            $result = $api->updateThemes($themes->pluck('slug')->toArray());

            foreach ($result['results'] ?? [] as $updateResult) {
                $theme = $themes->firstWhere('slug', $updateResult['slug']);
                if ($theme) {
                    UpdateLog::create([
                        'site_id' => $this->site->id,
                        'user_id' => auth()->id(),
                        'type' => 'theme',
                        'name' => $theme->name,
                        'slug' => $theme->slug,
                        'from_version' => $updateResult['from_version'] ?? $theme->version,
                        'to_version' => $updateResult['to_version'] ?? $theme->update_version,
                        'success' => $updateResult['success'] ?? false,
                        'error_message' => $updateResult['error'] ?? null,
                        'performed_at' => now(),
                    ]);
                }
            }

            SyncWordPressSite::dispatch($this->site);

            session()->flash('update-success', count($themes) . ' theme(s) update initiated.');
        } catch (\Exception $e) {
            session()->flash('update-error', "Bulk theme update failed: {$e->getMessage()}");
        }

        unset($this->themes, $this->themeCounts);
    }

    public function clearResult(string $key): void
    {
        unset($this->updateResults[$key]);
    }

    // ── Fix 2: Activate / Deactivate / Delete actions ──

    public function activatePlugin(int $id): void
    {
        $plugin = $this->site->sitePlugins()->findOrFail($id);

        try {
            $api = new WordPressApiService($this->site);
            $api->activatePlugin($plugin->file);
            SyncWordPressSite::dispatch($this->site);
            $this->updateResults['plugin_' . $id] = [
                'success' => true,
                'message' => "{$plugin->name} activated.",
                'version' => null,
            ];
        } catch (\Exception $e) {
            $this->updateResults['plugin_' . $id] = [
                'success' => false,
                'message' => "Activate failed: {$e->getMessage()}",
                'version' => null,
            ];
        }

        unset($this->plugins, $this->pluginCounts);
    }

    public function deactivatePlugin(int $id): void
    {
        $plugin = $this->site->sitePlugins()->findOrFail($id);

        try {
            $api = new WordPressApiService($this->site);
            $api->deactivatePlugin($plugin->file);
            SyncWordPressSite::dispatch($this->site);
            $this->updateResults['plugin_' . $id] = [
                'success' => true,
                'message' => "{$plugin->name} deactivated.",
                'version' => null,
            ];
        } catch (\Exception $e) {
            $this->updateResults['plugin_' . $id] = [
                'success' => false,
                'message' => "Deactivate failed: {$e->getMessage()}",
                'version' => null,
            ];
        }

        unset($this->plugins, $this->pluginCounts);
    }

    public function confirmDeletePlugin(int $id): void
    {
        $this->confirmingDeleteId = $id;
        $this->dispatch('open-modal-confirm-delete-plugin');
    }

    public function deletePlugin(): void
    {
        if (!$this->confirmingDeleteId) {
            return;
        }

        $plugin = $this->site->sitePlugins()->findOrFail($this->confirmingDeleteId);

        try {
            $api = new WordPressApiService($this->site);
            $api->deletePlugin($plugin->file);
            SyncWordPressSite::dispatch($this->site);
            session()->flash('update-success', "{$plugin->name} deleted.");
        } catch (\Exception $e) {
            session()->flash('update-error', "Delete failed: {$e->getMessage()}");
        }

        $this->confirmingDeleteId = null;
        $this->dispatch('close-modal-confirm-delete-plugin');
        unset($this->plugins, $this->pluginCounts);
    }

    public function activateTheme(int $id): void
    {
        $theme = $this->site->siteThemes()->findOrFail($id);

        try {
            $api = new WordPressApiService($this->site);
            $api->activateTheme($theme->slug);
            SyncWordPressSite::dispatch($this->site);
            $this->updateResults['theme_' . $id] = [
                'success' => true,
                'message' => "{$theme->name} activated.",
                'version' => null,
            ];
        } catch (\Exception $e) {
            $this->updateResults['theme_' . $id] = [
                'success' => false,
                'message' => "Activate failed: {$e->getMessage()}",
                'version' => null,
            ];
        }

        unset($this->themes, $this->themeCounts);
    }

    public function confirmDeleteTheme(int $id): void
    {
        $this->confirmingDeleteThemeId = $id;
        $this->dispatch('open-modal-confirm-delete-theme');
    }

    public function deleteTheme(): void
    {
        if (!$this->confirmingDeleteThemeId) {
            return;
        }

        $theme = $this->site->siteThemes()->findOrFail($this->confirmingDeleteThemeId);

        try {
            $api = new WordPressApiService($this->site);
            $api->deleteTheme($theme->slug);
            SyncWordPressSite::dispatch($this->site);
            session()->flash('update-success', "{$theme->name} deleted.");
        } catch (\Exception $e) {
            session()->flash('update-error', "Delete failed: {$e->getMessage()}");
        }

        $this->confirmingDeleteThemeId = null;
        $this->dispatch('close-modal-confirm-delete-theme');
        unset($this->themes, $this->themeCounts);
    }

    public function checkAbandonedNow(): void
    {
        $this->dispatchTrackedJob('abandoned', new CheckAbandonedPluginsJob($this->site), 'Checking for abandoned plugins...');
        unset($this->abandonedCounts, $this->lastAbandonedCheck, $this->plugins, $this->pluginCounts);
    }

    public function checkConflictsNow(): void
    {
        try {
            PluginConflictService::checkSite($this->site);
            session()->flash('update-success', 'Plugin conflict check completed.');
        } catch (\Exception $e) {
            session()->flash('update-error', "Conflict check failed: {$e->getMessage()}");
        }

        unset($this->activeConflicts);
    }

    public function dismissConflict(int $id): void
    {
        $conflict = $this->site->sitePluginConflicts()->findOrFail($id);
        PluginConflictService::dismiss($conflict);
        unset($this->activeConflicts);
    }

    public function syncNow(): void
    {
        $this->dispatchTrackedJob('sync', new SyncWordPressSite($this->site), 'Syncing site data...');
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        unset($this->plugins, $this->themes, $this->users, $this->pluginCounts, $this->themeCounts, $this->userCount, $this->abandonedCounts, $this->lastAbandonedCheck, $this->activeConflicts);
        $this->site->refresh();
    }

    public function render()
    {
        return view('livewire.sites.detail.site-plugins')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Plugins & Themes',
            ]);
    }
}
