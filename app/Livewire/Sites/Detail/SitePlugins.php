<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\CheckAbandonedPluginsJob;
use App\Jobs\CreateBackup;
use App\Jobs\RunSafeUpdate;
use App\Jobs\SyncWordPressSite;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\UpdateLog;
use App\Services\ActivityLogger;
use App\Services\PluginConflictService;
use App\Services\SafeUpdateService;
use App\Services\WordPressApiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SitePlugins extends Component
{
    use WithJobTracking, WithSiteAuthorization;

    private static ?bool $hasSiteUsersTable = null;
    private static ?bool $hasSitePluginConflictsTable = null;

    public Site $site;

    public string $tab = 'plugins';
    public string $filter = 'all';
    public string $search = '';

    // Fix 1: Per-item update results
    public array $updateResults = [];

    // Delete confirmation state
    public ?int $confirmingDeleteId = null;
    public ?string $confirmingDeleteName = null;
    public ?int $confirmingDeleteThemeId = null;
    public ?string $confirmingDeleteThemeName = null;
    public array $confirmingDeleteThemeChildren = [];

    // Rollback state
    public ?int $rollbackItemId = null;
    public ?string $rollbackType = null;

    // Safe Update Mode
    public bool $safeUpdateMode = false;

    // Detail modal
    public ?array $detailItem = null;

    protected function jobTrackingKeys(): array
    {
        return [
            'sync' => 'sync-wp-' . $this->site->id,
            'abandoned' => 'abandoned-plugins-' . $this->site->id,
            'safe-update' => 'safe-update-' . $this->site->id,
        ];
    }

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
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
            $escaped = $this->escapeLike($this->search);
            $query->where(function ($q) use ($escaped) {
                $q->where('name', 'like', '%' . $escaped . '%')
                  ->orWhere('slug', 'like', '%' . $escaped . '%')
                  ->orWhere('author', 'like', '%' . $escaped . '%');
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
                $q->where('name', 'like', '%' . $escaped . '%')
                  ->orWhere('slug', 'like', '%' . $escaped . '%')
                  ->orWhere('author', 'like', '%' . $escaped . '%');
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
            $escaped = $this->escapeLike($this->search);
            $query->where(function ($q) use ($escaped) {
                $q->where('username', 'like', '%' . $escaped . '%')
                  ->orWhere('display_name', 'like', '%' . $escaped . '%')
                  ->orWhere('email', 'like', '%' . $escaped . '%')
                  ->orWhere('role', 'like', '%' . $escaped . '%');
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
        $counts = $this->site->sitePlugins()
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN is_active = true THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN is_active = false THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN has_update = true THEN 1 ELSE 0 END) as updates,
                SUM(CASE WHEN is_abandoned = true OR is_closed = true THEN 1 ELSE 0 END) as issues
            ")
            ->first();

        return [
            'total' => (int) $counts->total,
            'active' => (int) $counts->active,
            'inactive' => (int) $counts->inactive,
            'updates' => (int) $counts->updates,
            'issues' => (int) $counts->issues,
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
                $q->where('name', 'like', '%' . $escaped . '%')
                  ->orWhere('slug', 'like', '%' . $escaped . '%');
            });
        }

        return $query->with('user')->orderByDesc('performed_at')->limit(100)->get();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->filter = 'all';
        $this->search = '';
        unset($this->plugins, $this->themes, $this->users, $this->updateHistory);
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $filter;
        unset($this->plugins, $this->themes, $this->updateHistory);
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
            ActivityLogger::pluginActivated($this->site, $plugin->name);
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
            ActivityLogger::pluginDeactivated($this->site, $plugin->name);
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
        $plugin = $this->site->sitePlugins()->findOrFail($id);
        $this->confirmingDeleteId = $id;
        $this->confirmingDeleteName = $plugin->name;
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
            ActivityLogger::pluginDeleted($this->site, $plugin->name);
            SyncWordPressSite::dispatch($this->site);
            session()->flash('update-success', "{$plugin->name} deleted.");
        } catch (\Exception $e) {
            session()->flash('update-error', "Delete failed: {$e->getMessage()}");
        }

        $this->confirmingDeleteId = null;
        $this->confirmingDeleteName = null;
        $this->dispatch('close-modal-confirm-delete-plugin');
        unset($this->plugins, $this->pluginCounts);
    }

    public function activateTheme(int $id): void
    {
        $theme = $this->site->siteThemes()->findOrFail($id);

        try {
            $api = new WordPressApiService($this->site);
            $api->activateTheme($theme->slug);
            ActivityLogger::themeActivated($this->site, $theme->name);
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

    public function deleteTheme(): void
    {
        if (!$this->confirmingDeleteThemeId) {
            return;
        }

        $theme = $this->site->siteThemes()->findOrFail($this->confirmingDeleteThemeId);

        try {
            $api = new WordPressApiService($this->site);
            $api->deleteTheme($theme->slug);
            ActivityLogger::themeDeleted($this->site, $theme->name);
            SyncWordPressSite::dispatch($this->site);
            session()->flash('update-success', "{$theme->name} deleted.");
        } catch (\Exception $e) {
            session()->flash('update-error', "Delete failed: {$e->getMessage()}");
        }

        $this->confirmingDeleteThemeId = null;
        $this->confirmingDeleteThemeName = null;
        $this->confirmingDeleteThemeChildren = [];
        $this->dispatch('close-modal-confirm-delete-theme');
        unset($this->themes, $this->themeCounts);
    }

    // ── Rollback ──

    #[Computed]
    public function rollbackHistory()
    {
        if (!$this->rollbackItemId || !$this->rollbackType) {
            return collect();
        }

        $query = UpdateLog::where('site_id', $this->site->id)
            ->where('type', $this->rollbackType)
            ->where('success', true)
            ->orderByDesc('performed_at');

        if ($this->rollbackType === 'plugin') {
            $plugin = $this->site->sitePlugins()->find($this->rollbackItemId);
            if ($plugin) {
                $query->where('slug', $plugin->slug);
            }
        } else {
            $theme = $this->site->siteThemes()->find($this->rollbackItemId);
            if ($theme) {
                $query->where('slug', $theme->slug);
            }
        }

        return $query->limit(10)->get();
    }

    public function showRollback(string $type, int $id): void
    {
        $this->rollbackType = $type;
        $this->rollbackItemId = $id;
        unset($this->rollbackHistory);
        $this->dispatch('open-modal-rollback');
    }

    public function rollbackTo(int $logId): void
    {
        $log = UpdateLog::where('site_id', $this->site->id)->findOrFail($logId);

        try {
            $api = new WordPressApiService($this->site);
            $api->rollback($log->type, $log->slug, $log->from_version);

            ActivityLogger::pluginRolledBack(
                $this->site,
                $log->name,
                $log->to_version ?? 'current',
                $log->from_version,
            );

            UpdateLog::create([
                'site_id' => $this->site->id,
                'user_id' => auth()->id(),
                'type' => $log->type,
                'name' => $log->name,
                'slug' => $log->slug,
                'from_version' => $log->to_version,
                'to_version' => $log->from_version,
                'success' => true,
                'error_message' => null,
                'performed_at' => now(),
            ]);

            SyncWordPressSite::dispatch($this->site);
            session()->flash('update-success', "{$log->name} rolled back to v{$log->from_version}.");
        } catch (\Exception $e) {
            session()->flash('update-error', "Rollback failed: {$e->getMessage()}");
        }

        $this->rollbackItemId = null;
        $this->rollbackType = null;
        $this->dispatch('close-modal-rollback');
        unset($this->plugins, $this->themes, $this->pluginCounts, $this->themeCounts, $this->updateHistory);
    }

    // ── Conflict resolution ──

    public function deactivateConflictPlugin(string $slug): void
    {
        $plugin = $this->site->sitePlugins()->where('slug', $slug)->first();

        if (!$plugin) {
            session()->flash('update-error', "Plugin not found: {$slug}");
            return;
        }

        try {
            $api = new WordPressApiService($this->site);
            $api->deactivatePlugin($plugin->file);
            ActivityLogger::pluginDeactivated($this->site, $plugin->name);
            SyncWordPressSite::dispatch($this->site);
            session()->flash('update-success', "{$plugin->name} deactivated to resolve conflict.");
        } catch (\Exception $e) {
            session()->flash('update-error', "Deactivate failed: {$e->getMessage()}");
        }

        unset($this->plugins, $this->pluginCounts, $this->activeConflicts);
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

    // ── Quick Actions ──

    public function openWpAdmin(): void
    {
        try {
            $api = new WordPressApiService($this->site);
            $result = $api->getLoginUrl();

            if (!empty($result['login_url'])) {
                $this->js("window.open('" . addslashes($result['login_url']) . "', '_blank')");
                return;
            }

            session()->flash('update-error', 'Could not generate login URL. No URL returned.');
        } catch (\Exception $e) {
            session()->flash('update-error', 'Could not generate login URL: ' . $e->getMessage());
        }
    }

    public function quickBackup(): void
    {
        dispatch(new CreateBackup($this->site, 'full', 'manual'));
        $this->dispatch('notify', type: 'success', message: 'Backup started in background.');
    }

    // ── Safe Update Methods ──

    public function safeUpdatePlugin(int $pluginId): void
    {
        $plugin = $this->site->sitePlugins()->findOrFail($pluginId);

        $safeUpdate = app(SafeUpdateService::class)->createSafeUpdate(
            $this->site, 'plugin', $plugin->file, $plugin->name,
            $plugin->version, $plugin->update_version
        );

        RunSafeUpdate::dispatch($safeUpdate, auth()->id());

        session()->flash('update-success', "Safe update initiated for {$plugin->name}. Backup → Update → Health Check will run automatically.");
        unset($this->activeSafeUpdates);
    }

    public function safeUpdateTheme(int $themeId): void
    {
        $theme = $this->site->siteThemes()->findOrFail($themeId);

        $safeUpdate = app(SafeUpdateService::class)->createSafeUpdate(
            $this->site, 'theme', $theme->slug, $theme->name,
            $theme->version, $theme->update_version
        );

        RunSafeUpdate::dispatch($safeUpdate, auth()->id());

        session()->flash('update-success', "Safe update initiated for {$theme->name}. Backup → Update → Health Check will run automatically.");
        unset($this->activeSafeUpdates);
    }

    public function safeUpdateCore(): void
    {
        $safeUpdate = app(SafeUpdateService::class)->createSafeUpdate(
            $this->site, 'core', 'wordpress', 'WordPress Core',
            $this->site->wp_version, $this->site->core_update_version
        );

        RunSafeUpdate::dispatch($safeUpdate, auth()->id());

        session()->flash('update-success', 'Safe core update initiated. Backup → Update → Health Check will run automatically.');
        unset($this->activeSafeUpdates);
    }

    #[Computed]
    public function activeSafeUpdates()
    {
        return $this->site->safeUpdates()
            ->whereIn('status', ['pending', 'backing_up', 'updating', 'health_checking', 'rolling_back'])
            ->orderByDesc('started_at')
            ->get();
    }

    // ── Core Update ──

    public function updateCore(): void
    {
        $this->runPreUpdateBackup();

        try {
            $api = new WordPressApiService($this->site);
            $result = $api->updateCore();

            UpdateLog::create([
                'site_id' => $this->site->id,
                'user_id' => auth()->id(),
                'type' => 'core',
                'name' => 'WordPress Core',
                'slug' => 'wordpress',
                'from_version' => $this->site->wp_version,
                'to_version' => $this->site->core_update_version,
                'success' => $result['success'] ?? false,
                'error_message' => $result['error'] ?? null,
                'performed_at' => now(),
            ]);

            ActivityLogger::coreUpdated($this->site, $this->site->wp_version, $this->site->core_update_version);
            SyncWordPressSite::dispatch($this->site);
            session()->flash('update-success', 'WordPress core update initiated.');
        } catch (\Exception $e) {
            session()->flash('update-error', "Core update failed: {$e->getMessage()}");
        }
    }

    private function runPreUpdateBackup(): void
    {
        $config = $this->site->backupConfig;
        if ($config?->backup_before_updates) {
            try {
                CreateBackup::dispatchSync($this->site, 'database', 'pre_update', $config->storage_destination_id);
            } catch (\Exception $e) {
                Log::warning("Pre-update backup failed for site {$this->site->id}: {$e->getMessage()}");
            }
        }
    }

    // ── Auto-Update Toggle ──

    public function toggleAutoUpdate(string $type, int $id): void
    {
        if ($type === 'plugin') {
            $item = $this->site->sitePlugins()->findOrFail($id);
        } else {
            $item = $this->site->siteThemes()->findOrFail($id);
        }

        $item->update(['auto_update' => !$item->auto_update]);

        $this->dispatch('notify', type: 'success', message: ($item->auto_update ? 'Enabled' : 'Disabled') . " auto-updates for {$item->name}.");
        unset($this->plugins, $this->themes);
    }

    // ── Detail Modal ──

    public function showDetail(string $type, int $id): void
    {
        if ($type === 'plugin') {
            $item = $this->site->sitePlugins()->findOrFail($id);
        } else {
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
        unset($this->plugins, $this->themes, $this->users, $this->pluginCounts, $this->themeCounts, $this->userCount, $this->abandonedCounts, $this->lastAbandonedCheck, $this->activeConflicts, $this->updateHistory, $this->activeSafeUpdates);
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
