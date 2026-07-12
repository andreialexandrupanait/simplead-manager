<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Jobs\CreateBackup;
use App\Models\SitePlugin;
use App\Models\UpdateLog;
use App\Services\PluginManagerService;

trait WithPluginManagement
{
    public ?int $confirmingDeleteId = null;

    public ?string $confirmingDeleteName = null;

    public function updatePlugin(int $pluginId): void
    {
        $this->authorizeSiteModification($this->site);

        // When the site has opted into safe updates, route through the queued
        // pipeline (backup → update → health check → visual regression →
        // auto-rollback) instead of updating inline with no safety net.
        if ($this->site->safe_updates_enabled) {
            $this->queueSafeUpdate($pluginId);

            return;
        }

        $result = $this->updateSinglePlugin($pluginId);
        $this->updateResults['plugin_'.$pluginId] = $result;
    }

    public function toggleSafeUpdates(): void
    {
        $this->authorizeSiteModification($this->site);

        $this->site->update(['safe_updates_enabled' => ! $this->site->safe_updates_enabled]);
        $this->dispatch('notify', type: 'success', message: $this->site->safe_updates_enabled
            ? 'Safe updates enabled — plugin updates now back up, health-check and auto-rollback.'
            : 'Safe updates disabled — plugin updates run inline.');
    }

    protected function queueSafeUpdate(int $pluginId): void
    {
        /** @var SitePlugin $plugin */
        $plugin = $this->site->sitePlugins()->findOrFail($pluginId);

        if (! $plugin->has_update) {
            $this->dispatch('notify', type: 'error', message: 'No update available for this plugin.');

            return;
        }

        $service = app(\App\Services\SafeUpdateService::class);
        $safeUpdate = $service->createSafeUpdate(
            $this->site, 'plugin', $plugin->slug, $plugin->name,
            $plugin->version ?? '', $plugin->update_version ?? '',
            $plugin->file, // connector identifier (see AUDIT PM-P0-1)
        );

        \App\Jobs\RunSafeUpdate::dispatch($safeUpdate, auth()->id());

        $this->dispatch('notify', type: 'success',
            message: "Safe update queued for {$plugin->name} — it will back up, update, health-check and roll back automatically if anything breaks.");
    }

    public function updateSinglePlugin(int $pluginId): array
    {
        $this->authorizeSiteModification($this->site);

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

    public function activatePlugin(int $id): void
    {
        $this->authorizeSiteModification($this->site);

        $result = app(PluginManagerService::class)->activatePlugin($this->site, $id);
        $this->updateResults['plugin_'.$id] = $result + ['version' => null];
        $this->dispatch('notify', type: $result['success'] ? 'success' : 'error', message: $result['message']);
        unset($this->plugins, $this->pluginCounts);
    }

    public function deactivatePlugin(int $id): void
    {
        $this->authorizeSiteModification($this->site);

        $result = app(PluginManagerService::class)->deactivatePlugin($this->site, $id);
        $this->updateResults['plugin_'.$id] = $result + ['version' => null];
        $this->dispatch('notify', type: $result['success'] ? 'success' : 'error', message: $result['message']);
        unset($this->plugins, $this->pluginCounts);
    }

    public function confirmDeletePlugin(int $id): void
    {
        $this->authorizeSiteModification($this->site);

        /** @var SitePlugin $plugin */
        $plugin = $this->site->sitePlugins()->findOrFail($id);
        $this->confirmingDeleteId = $id;
        $this->confirmingDeleteName = $plugin->name;
        $this->dispatch('open-modal-confirm-delete-plugin');
    }

    public function deletePlugin(): void
    {
        $this->authorizeSiteModification($this->site);

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

    public function deletePluginDirect(int $id): array
    {
        $this->authorizeSiteModification($this->site);

        $result = app(PluginManagerService::class)->deletePlugin($this->site, $id);

        if (! $result['success']) {
            $this->updateResults['plugin_'.$id] = ['success' => false, 'message' => $result['message'], 'version' => null];
        }

        unset($this->plugins, $this->pluginCounts);

        return $result;
    }

    public function bulkUpdatePlugins(array $ids): array
    {
        $this->authorizeSiteModification($this->site);

        $this->runPreUpdateBackup();
        $result = app(PluginManagerService::class)->bulkUpdatePlugins($this->site, $ids);
        $this->updateResults = array_merge($this->updateResults, $result['results']);
        unset($this->plugins, $this->pluginCounts);

        return ['success' => $result['success'], 'failed' => $result['failed']];
    }

    public function getUpdatablePluginIds(): array
    {
        return $this->site->sitePlugins()->where('has_update', true)->pluck('id')->toArray();
    }

    public function getFilteredPluginIds(): array
    {
        return $this->plugins->pluck('id')->toArray();
    }

    public function updateLicense(int $pluginId, ?string $licenseKey, ?string $expiresAt, ?string $status): void
    {
        $this->authorizeSiteModification($this->site);

        $plugin = SitePlugin::where('site_id', $this->site->id)->findOrFail($pluginId);
        $plugin->update([
            'license_key' => $licenseKey ?: null,
            'license_expires_at' => $expiresAt ?: null,
            'license_status' => $status ?: null,
        ]);

        unset($this->plugins);
    }

    public function rollbackPlugin(int $pluginId): void
    {
        $this->authorizeSiteModification($this->site);

        $plugin = SitePlugin::where('site_id', $this->site->id)->findOrFail($pluginId);

        // Update logs store the plugin's display name in `name` and its slug in
        // `slug`; looking up the last update by `name = slug` never matched, so the
        // rollback button was dead. Match on the slug column (P1-43).
        $lastUpdate = UpdateLog::where('site_id', $this->site->id)
            ->where('type', 'plugin')
            ->where('slug', $plugin->slug)
            ->where('success', true)
            ->orderByDesc('performed_at')
            ->first();

        if (! $lastUpdate || ! $lastUpdate->from_version) {
            session()->flash('plugin-error', 'No previous version found to rollback to.');

            return;
        }

        try {
            $api = app(\App\Services\WordPressApiServiceFactory::class)->make($this->site);
            $result = $api->rollback('plugin', $plugin->slug, $lastUpdate->from_version);

            if (! empty($result['success'])) {
                $plugin->update(['version' => $lastUpdate->from_version, 'has_update' => true]);

                UpdateLog::create([
                    'site_id' => $this->site->id,
                    'user_id' => auth()->id(),
                    'type' => 'plugin',
                    'name' => $plugin->name,
                    'slug' => $plugin->slug,
                    'from_version' => $plugin->version,
                    'to_version' => $lastUpdate->from_version,
                    'success' => true,
                    'performed_at' => now(),
                ]);

                unset($this->plugins, $this->updateHistory);
                session()->flash('plugin-success', "{$plugin->name} rolled back to {$lastUpdate->from_version}.");
            } else {
                session()->flash('plugin-error', 'Rollback failed: '.($result['error']['message'] ?? 'Unknown error'));
            }
        } catch (\Throwable $e) {
            session()->flash('plugin-error', 'Rollback failed: '.$e->getMessage());
        }
    }

    private function runPreUpdateBackup(): void
    {
        $config = $this->site->backupConfig;
        if ($config?->backup_before_updates) {
            // Run the backup SYNCHRONOUSLY so it completes before the inline
            // update touches the site. Dispatching it async raced the update and
            // could capture post-update state (or run after it), defeating the
            // point of a pre-update restore point (P1-18).
            CreateBackup::dispatchSync($this->site, 'database', 'pre_update', $config->storage_destination_id);
        }
    }
}
