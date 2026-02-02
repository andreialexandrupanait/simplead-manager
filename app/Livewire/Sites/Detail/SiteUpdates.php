<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\CreateBackup;
use App\Jobs\SyncWordPressSite;
use App\Models\Site;
use App\Models\UpdateLog;
use App\Services\WordPressApiService;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class SiteUpdates extends Component
{
    use WithPagination;

    public Site $site;

    public function mount(Site $site): void
    {
        $this->site = $site;
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

    public function updatePlugin(int $pluginId): void
    {
        $this->runPreUpdateBackup();

        $plugin = $this->site->sitePlugins()->findOrFail($pluginId);

        try {
            $api = new WordPressApiService($this->site);
            $result = $api->updatePlugins([$plugin->file]);

            $updateResult = $result['results'][0] ?? [];

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

            SyncWordPressSite::dispatch($this->site);

            session()->flash('update-success', "{$plugin->name} updated successfully.");
        } catch (\Exception $e) {
            session()->flash('update-error', "Failed to update {$plugin->name}: {$e->getMessage()}");
        }

        unset($this->availableUpdates);
    }

    public function updateTheme(int $themeId): void
    {
        $this->runPreUpdateBackup();

        $theme = $this->site->siteThemes()->findOrFail($themeId);

        try {
            $api = new WordPressApiService($this->site);
            $result = $api->updateThemes([$theme->slug]);

            $updateResult = $result['results'][0] ?? [];

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

            SyncWordPressSite::dispatch($this->site);

            session()->flash('update-success', "{$theme->name} updated successfully.");
        } catch (\Exception $e) {
            session()->flash('update-error', "Failed to update {$theme->name}: {$e->getMessage()}");
        }

        unset($this->availableUpdates);
    }

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
                'to_version' => $result['to_version'] ?? $this->site->core_update_version,
                'success' => $result['success'] ?? false,
                'error_message' => $result['error'] ?? null,
                'performed_at' => now(),
            ]);

            SyncWordPressSite::dispatch($this->site);

            session()->flash('update-success', 'WordPress core update initiated.');
        } catch (\Exception $e) {
            session()->flash('update-error', "Core update failed: {$e->getMessage()}");
        }

        unset($this->availableUpdates);
    }

    public function updateAll(): void
    {
        $this->runPreUpdateBackup();

        // Update core first
        if ($this->site->core_update_version) {
            $this->updateCore();
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
            } catch (\Exception $e) {
                session()->flash('update-error', "Theme updates failed: {$e->getMessage()}");
            }
        }

        SyncWordPressSite::dispatch($this->site);
        session()->flash('update-success', 'All updates initiated.');

        unset($this->availableUpdates);
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
        SyncWordPressSite::dispatch($this->site);
        session()->flash('sync-dispatched', 'Sync job has been dispatched.');
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
