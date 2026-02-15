<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\CreateBackup;
use App\Jobs\SyncWordPressSite;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\UpdateLog;
use App\Services\ActivityLogger;
use App\Services\WordPressApiService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SitePlugins extends Component
{
    use WithJobTracking, WithSiteAuthorization;

    private static ?bool $hasSiteUsersTable = null;

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
            'sync' => 'sync-wp-' . $this->site->id,
            'backup' => 'backup-' . $this->site->id,
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

    private function cleanErrorMessage(string $message): string
    {
        return \Illuminate\Support\Str::limit(trim(strip_tags($message)), 200);
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
        ];
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
            if (!$success) {
                $errorMsg = $this->cleanErrorMessage($updateResult['error'] ?? 'Update failed');
                $this->dispatch('notify', type: 'error', message: "Update failed for {$name}: " . $errorMsg);
            }
            return [
                'success' => $success,
                'message' => $success
                    ? "Updated to v" . ($updateResult['to_version'] ?? $updateVersion)
                    : $this->cleanErrorMessage($updateResult['error'] ?? 'Update failed'),
                'version' => $updateResult['to_version'] ?? $updateVersion,
            ];
        } catch (\Exception $e) {
            Log::warning("{$type} update failed: {$name} on site {$this->site->name}", [
                'type' => $type,
                'slug' => $slug,
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
            $cleanMsg = $this->cleanErrorMessage($e->getMessage());
            $this->dispatch('notify', type: 'error', message: "Update failed for {$name}: " . $cleanMsg);
            return [
                'success' => false,
                'message' => "Failed: " . $cleanMsg,
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
            Log::warning("Plugin activation failed: {$plugin->name} on site {$this->site->name}", [
                'plugin' => $plugin->file,
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
            $cleanMsg = $this->cleanErrorMessage($e->getMessage());
            $this->updateResults['plugin_' . $id] = [
                'success' => false,
                'message' => "Activate failed: " . $cleanMsg,
                'version' => null,
            ];
            $this->dispatch('notify', type: 'error', message: "Activate failed: " . $cleanMsg);
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
            Log::warning("Plugin deactivation failed: {$plugin->name} on site {$this->site->name}", [
                'plugin' => $plugin->file,
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
            $cleanMsg = $this->cleanErrorMessage($e->getMessage());
            $this->updateResults['plugin_' . $id] = [
                'success' => false,
                'message' => "Deactivate failed: " . $cleanMsg,
                'version' => null,
            ];
            $this->dispatch('notify', type: 'error', message: "Deactivate failed: " . $cleanMsg);
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
            $plugin->delete();
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
            Log::warning("Theme activation failed: {$theme->name} on site {$this->site->name}", [
                'theme' => $theme->slug,
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
            $cleanMsg = $this->cleanErrorMessage($e->getMessage());
            $this->updateResults['theme_' . $id] = [
                'success' => false,
                'message' => "Activate failed: " . $cleanMsg,
                'version' => null,
            ];
            $this->dispatch('notify', type: 'error', message: "Activate failed: " . $cleanMsg);
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

    public function deleteThemeById(int $id): void
    {
        $theme = $this->site->siteThemes()->findOrFail($id);

        try {
            $api = new WordPressApiService($this->site);
            $api->deleteTheme($theme->slug);
            $theme->delete();
            ActivityLogger::themeDeleted($this->site, $theme->name);
            SyncWordPressSite::dispatch($this->site);
            $this->dispatch('notify', type: 'success', message: "{$theme->name} deleted.");
        } catch (\Exception $e) {
            Log::error('deleteTheme failed', ['error' => $e->getMessage(), 'theme' => $theme->slug]);
            $this->dispatch('notify', type: 'error', message: "Delete failed: " . $this->cleanErrorMessage($e->getMessage()));
        }

        unset($this->themes, $this->themeCounts);
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
            $theme->delete();
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

    // ── Bulk Actions ──

    public function deletePluginDirect(int $id): array
    {
        $plugin = $this->site->sitePlugins()->findOrFail($id);

        try {
            $api = new WordPressApiService($this->site);
            $api->deletePlugin($plugin->file);
            $plugin->delete();
            ActivityLogger::pluginDeleted($this->site, $plugin->name);
            unset($this->plugins, $this->pluginCounts);
            return ['success' => true, 'message' => "{$plugin->name} deleted.", 'name' => $plugin->name];
        } catch (\Exception $e) {
            Log::warning("Plugin delete failed: {$plugin->name} on site {$this->site->name}", [
                'plugin' => $plugin->file,
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
            $msg = "Delete failed: " . $this->cleanErrorMessage($e->getMessage());
            $this->updateResults['plugin_' . $id] = ['success' => false, 'message' => $msg, 'version' => null];
            unset($this->plugins, $this->pluginCounts);
            return ['success' => false, 'message' => $msg, 'name' => $plugin->name];
        }
    }

    public function deleteThemeDirect(int $id): array
    {
        $theme = $this->site->siteThemes()->findOrFail($id);

        try {
            $api = new WordPressApiService($this->site);
            $api->deleteTheme($theme->slug);
            $theme->delete();
            ActivityLogger::themeDeleted($this->site, $theme->name);
            unset($this->themes, $this->themeCounts);
            return ['success' => true, 'message' => "{$theme->name} deleted.", 'name' => $theme->name];
        } catch (\Exception $e) {
            Log::warning("Theme delete failed: {$theme->name} on site {$this->site->name}", [
                'theme' => $theme->slug,
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
            $msg = "Delete failed: " . $this->cleanErrorMessage($e->getMessage());
            $this->updateResults['theme_' . $id] = ['success' => false, 'message' => $msg, 'version' => null];
            unset($this->themes, $this->themeCounts);
            return ['success' => false, 'message' => $msg, 'name' => $theme->name];
        }
    }

    public function bulkUpdatePlugins(array $ids): array
    {
        $plugins = $this->site->sitePlugins()->whereIn('id', $ids)->where('has_update', true)->get();
        if ($plugins->isEmpty()) {
            return ['success' => 0, 'failed' => 0];
        }

        $this->runPreUpdateBackup();
        $api = new WordPressApiService($this->site);

        try {
            $result = $api->updatePlugins($plugins->pluck('file')->toArray());
            $apiResults = $result['results'] ?? [];
        } catch (\Exception $e) {
            return ['success' => 0, 'failed' => count($plugins), 'error' => $e->getMessage()];
        }

        $success = 0;
        $failed = 0;

        foreach ($plugins as $plugin) {
            $updateResult = $apiResults[$plugin->file] ?? [];
            $wasSuccess = $updateResult['success'] ?? false;

            UpdateLog::create([
                'site_id' => $this->site->id,
                'user_id' => auth()->id(),
                'type' => 'plugin',
                'name' => $plugin->name,
                'slug' => $plugin->slug,
                'from_version' => $plugin->version,
                'to_version' => $plugin->update_version,
                'success' => $wasSuccess,
                'error_message' => $updateResult['error'] ?? null,
                'performed_at' => now(),
            ]);

            if ($wasSuccess) {
                $success++;
                $this->updateResults['plugin_' . $plugin->id] = [
                    'success' => true,
                    'message' => "Updated to v{$plugin->update_version}",
                    'version' => $plugin->update_version,
                ];
            } else {
                $failed++;
                $this->updateResults['plugin_' . $plugin->id] = [
                    'success' => false,
                    'message' => $this->cleanErrorMessage($updateResult['error'] ?? 'Update failed'),
                    'version' => null,
                ];
            }
        }

        SyncWordPressSite::dispatch($this->site);
        unset($this->plugins, $this->pluginCounts);

        return ['success' => $success, 'failed' => $failed];
    }

    public function bulkUpdateThemes(array $ids): array
    {
        $themes = $this->site->siteThemes()->whereIn('id', $ids)->where('has_update', true)->get();
        if ($themes->isEmpty()) {
            return ['success' => 0, 'failed' => 0];
        }

        $this->runPreUpdateBackup();
        $api = new WordPressApiService($this->site);

        try {
            $result = $api->updateThemes($themes->pluck('slug')->toArray());
            $apiResults = $result['results'] ?? [];
        } catch (\Exception $e) {
            return ['success' => 0, 'failed' => count($themes), 'error' => $e->getMessage()];
        }

        $success = 0;
        $failed = 0;

        foreach ($themes as $theme) {
            $updateResult = $apiResults[$theme->slug] ?? [];
            $wasSuccess = $updateResult['success'] ?? false;

            UpdateLog::create([
                'site_id' => $this->site->id,
                'user_id' => auth()->id(),
                'type' => 'theme',
                'name' => $theme->name,
                'slug' => $theme->slug,
                'from_version' => $theme->version,
                'to_version' => $theme->update_version,
                'success' => $wasSuccess,
                'error_message' => $updateResult['error'] ?? null,
                'performed_at' => now(),
            ]);

            if ($wasSuccess) {
                $success++;
                $this->updateResults['theme_' . $theme->id] = [
                    'success' => true,
                    'message' => "Updated to v{$theme->update_version}",
                    'version' => $theme->update_version,
                ];
            } else {
                $failed++;
                $this->updateResults['theme_' . $theme->id] = [
                    'success' => false,
                    'message' => $this->cleanErrorMessage($updateResult['error'] ?? 'Update failed'),
                    'version' => null,
                ];
            }
        }

        SyncWordPressSite::dispatch($this->site);
        unset($this->themes, $this->themeCounts);

        return ['success' => $success, 'failed' => $failed];
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
        $this->dispatchTrackedJob('backup', new CreateBackup($this->site, 'full', 'manual'), 'Creating backup...');
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
        unset($this->plugins, $this->themes, $this->users, $this->pluginCounts, $this->themeCounts, $this->userCount, $this->updateHistory);
        $this->site->refresh();
    }

    public function render()
    {
        $view = view('livewire.sites.detail.site-plugins');

        if (!$this->embedded) {
            $view->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Plugins & Themes',
            ]);
        }

        return $view;
    }
}
