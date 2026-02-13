<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\CreateBackup;
use App\Jobs\ExecuteRollback;
use App\Jobs\RunSafeUpdate;
use App\Jobs\SyncWordPressSite;
use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\RollbackPoint;
use App\Models\Site;
use App\Models\UpdateLog;
use App\Services\ActivityLogger;
use App\Services\RollbackService;
use App\Services\SafeUpdateService;
use App\Services\WordPressApiService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class SiteUpdates extends Component
{
    use WithPagination, WithJobTracking, WithSiteAuthorization;

    public Site $site;

    public ?int $rollbackPointId = null;

    // Safe Update Mode
    public bool $safeUpdateMode = false;

    protected function jobTrackingKeys(): array
    {
        return ['sync' => 'sync-wp-' . $this->site->id];
    }

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->initJobTracking();
    }

    #[Computed]
    public function availableUpdates()
    {
        $updates = collect();

        // Core update
        if ($this->site->core_update_version) {
            $updates->push([
                'id' => 'core',
                'type' => 'core',
                'name' => 'WordPress Core',
                'slug' => 'wordpress',
                'from_version' => $this->site->wp_version,
                'to_version' => $this->site->core_update_version,
            ]);
        }

        // Plugin updates
        $this->site->sitePlugins()->where('has_update', true)->get()->each(function ($plugin) use ($updates) {
            $updates->push([
                'id' => 'plugin-' . $plugin->id,
                'type' => 'plugin',
                'name' => $plugin->name,
                'slug' => $plugin->slug,
                'file' => $plugin->file,
                'from_version' => $plugin->version,
                'to_version' => $plugin->update_version,
                'model_id' => $plugin->id,
            ]);
        });

        // Theme updates
        $this->site->siteThemes()->where('has_update', true)->get()->each(function ($theme) use ($updates) {
            $updates->push([
                'id' => 'theme-' . $theme->id,
                'type' => 'theme',
                'name' => $theme->name,
                'slug' => $theme->slug,
                'from_version' => $theme->version,
                'to_version' => $theme->update_version,
                'model_id' => $theme->id,
            ]);
        });

        return $updates;
    }

    public function getUpdateHistoryProperty()
    {
        return $this->site->updateLogs()
            ->with('user')
            ->orderByDesc('performed_at')
            ->paginate(20);
    }

    #[Computed]
    public function rollbackPoints()
    {
        return app(RollbackService::class)->getAvailablePoints($this->site);
    }

    #[Computed]
    public function activeSafeUpdates()
    {
        return $this->site->safeUpdates()
            ->whereIn('status', ['pending', 'backing_up', 'updating', 'health_checking', 'rolling_back'])
            ->orderByDesc('started_at')
            ->get();
    }

    public function updatePlugin(int $pluginId): void
    {
        $this->runPreUpdateBackup();

        $plugin = $this->site->sitePlugins()->findOrFail($pluginId);

        try {
            $api = new WordPressApiService($this->site);
            $result = $api->updatePlugins([$plugin->file]);
            $updateResult = $result['results'][0] ?? [];

            $this->logUpdate('plugin', $plugin->name, $plugin->slug, $plugin->version, $plugin->update_version, $updateResult);

            SyncWordPressSite::dispatch($this->site);
            session()->flash('update-success', "{$plugin->name} updated successfully.");
        } catch (\Exception $e) {
            session()->flash('update-error', "Failed to update {$plugin->name}: {$e->getMessage()}");
        }

        unset($this->availableUpdates, $this->rollbackPoints);
    }

    public function updateTheme(int $themeId): void
    {
        $this->runPreUpdateBackup();

        $theme = $this->site->siteThemes()->findOrFail($themeId);

        try {
            $api = new WordPressApiService($this->site);
            $result = $api->updateThemes([$theme->slug]);
            $updateResult = $result['results'][0] ?? [];

            $this->logUpdate('theme', $theme->name, $theme->slug, $theme->version, $theme->update_version, $updateResult);

            SyncWordPressSite::dispatch($this->site);
            session()->flash('update-success', "{$theme->name} updated successfully.");
        } catch (\Exception $e) {
            session()->flash('update-error', "Failed to update {$theme->name}: {$e->getMessage()}");
        }

        unset($this->availableUpdates, $this->rollbackPoints);
    }

    public function updateCore(): void
    {
        $this->runPreUpdateBackup();

        try {
            $api = new WordPressApiService($this->site);
            $result = $api->updateCore();

            $this->logUpdate('core', 'WordPress Core', 'wordpress', $this->site->wp_version, $this->site->core_update_version, $result);

            SyncWordPressSite::dispatch($this->site);
            session()->flash('update-success', 'WordPress core update initiated.');
        } catch (\Exception $e) {
            session()->flash('update-error', "Core update failed: {$e->getMessage()}");
        }

        unset($this->availableUpdates, $this->rollbackPoints);
    }

    public function updateAll(): void
    {
        $this->runPreUpdateBackup();

        // Update core first
        if ($this->site->core_update_version) {
            try {
                $api = new WordPressApiService($this->site);
                $result = $api->updateCore();
                $this->logUpdate('core', 'WordPress Core', 'wordpress', $this->site->wp_version, $this->site->core_update_version, $result);
            } catch (\Exception $e) {
                session()->flash('update-error', "Core update failed: {$e->getMessage()}");
            }
        }

        // Update all plugins
        $plugins = $this->site->sitePlugins()->where('has_update', true)->get();
        if ($plugins->isNotEmpty()) {
            try {
                $api = new WordPressApiService($this->site);
                $result = $api->updatePlugins($plugins->pluck('file')->toArray());

                foreach ($result['results'] ?? [] as $updateResult) {
                    $plugin = $plugins->firstWhere('file', $updateResult['file']);
                    if ($plugin) {
                        $this->logUpdate('plugin', $plugin->name, $plugin->slug, $plugin->version, $plugin->update_version, $updateResult);
                    }
                }
            } catch (\Exception $e) {
                session()->flash('update-error', "Plugin updates failed: {$e->getMessage()}");
            }
        }

        // Update all themes
        $themes = $this->site->siteThemes()->where('has_update', true)->get();
        if ($themes->isNotEmpty()) {
            try {
                $api = new WordPressApiService($this->site);
                $result = $api->updateThemes($themes->pluck('slug')->toArray());

                foreach ($result['results'] ?? [] as $updateResult) {
                    $theme = $themes->firstWhere('slug', $updateResult['slug']);
                    if ($theme) {
                        $this->logUpdate('theme', $theme->name, $theme->slug, $theme->version, $theme->update_version, $updateResult);
                    }
                }
            } catch (\Exception $e) {
                session()->flash('update-error', "Theme updates failed: {$e->getMessage()}");
            }
        }

        SyncWordPressSite::dispatch($this->site);
        session()->flash('update-success', 'All updates initiated.');

        unset($this->availableUpdates, $this->rollbackPoints);
    }

    // Rollback methods
    public function openRollbackModal(int $pointId): void
    {
        $this->rollbackPointId = $pointId;
        $this->dispatch('open-modal-rollback-confirm');
    }

    public function executeRollback(): void
    {
        $point = $this->site->rollbackPoints()->findOrFail($this->rollbackPointId);
        ExecuteRollback::dispatch($point, auth()->id());
        $this->dispatch('close-modal-rollback-confirm');
        $this->rollbackPointId = null;
        session()->flash('update-success', "Rollback initiated for {$point->slug}. It will be processed shortly.");
        unset($this->rollbackPoints);
    }

    // Safe Update methods
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

    private function logUpdate(string $type, string $name, string $slug, ?string $fromVersion, ?string $toVersion, array $updateResult): void
    {
        $actualFrom = $updateResult['from_version'] ?? $fromVersion;
        $actualTo = $updateResult['to_version'] ?? $toVersion;

        UpdateLog::create([
            'site_id' => $this->site->id,
            'user_id' => auth()->id(),
            'type' => $type,
            'name' => $name,
            'slug' => $slug,
            'from_version' => $actualFrom,
            'to_version' => $actualTo,
            'success' => $updateResult['success'] ?? false,
            'error_message' => $updateResult['error'] ?? null,
            'performed_at' => now(),
        ]);

        if ($updateResult['success'] ?? false) {
            app(RollbackService::class)->createRollbackPoint(
                $this->site, $type, $slug, $actualFrom, $actualTo,
            );
        }

        match ($type) {
            'plugin' => ActivityLogger::pluginUpdated($this->site, $name, $actualFrom, $actualTo),
            'theme' => ActivityLogger::themeUpdated($this->site, $name, $actualFrom, $actualTo),
            'core' => ActivityLogger::coreUpdated($this->site, $actualFrom, $actualTo),
        };
    }

    protected function runPreUpdateBackup(): void
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

    public function syncNow(): void
    {
        $this->dispatchTrackedJob('sync', new SyncWordPressSite($this->site), 'Syncing site data...');
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        unset($this->availableUpdates, $this->rollbackPoints, $this->activeSafeUpdates);
        $this->site->refresh();
    }

    public function render()
    {
        return view('livewire.sites.detail.site-updates', [
            'updateHistory' => $this->updateHistory,
        ])
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Updates',
            ]);
    }
}
