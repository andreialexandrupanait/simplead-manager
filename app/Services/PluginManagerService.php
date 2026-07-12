<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\WordPressApiException;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Models\SiteTheme;
use App\Models\UpdateLog;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PluginManagerService
{
    public function __construct(
        private readonly WordPressApiServiceFactory $apiFactory,
    ) {}

    /**
     * Update a single plugin or theme.
     *
     * @return array{success: bool, message: string, version: ?string}
     */
    public function performUpdate(
        Site $site,
        string $type,
        string $identifier,
        string $name,
        string $slug,
        ?string $currentVersion,
        ?string $updateVersion,
    ): array {
        try {
            $api = $this->apiFactory->make($site);
            $result = $type === 'plugin'
                ? $api->updatePlugins([$identifier])
                : $api->updateThemes([$identifier]);

            $updateResult = $result['results'][$identifier] ?? [];

            UpdateLog::create([
                'site_id' => $site->id,
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

            $success = $updateResult['success'] ?? false;

            return [
                'success' => $success,
                'message' => $success
                    ? 'Updated to v'.($updateResult['to_version'] ?? $updateVersion)
                    : $this->cleanErrorMessage($updateResult['error'] ?? 'Update failed'),
                'version' => $updateResult['to_version'] ?? $updateVersion,
            ];
        } catch (WordPressApiException|RequestException|\RuntimeException $e) {
            Log::warning("{$type} update failed: {$name} on site {$site->name}", [
                'type' => $type,
                'slug' => $slug,
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Failed: '.$this->cleanErrorMessage($e->getMessage()),
                'version' => null,
            ];
        }
    }

    /**
     * Activate a plugin by its model ID.
     *
     * @return array{success: bool, message: string}
     */
    public function activatePlugin(Site $site, int $pluginId): array
    {
        /** @var SitePlugin $plugin */
        $plugin = $site->sitePlugins()->findOrFail($pluginId);

        try {
            $api = $this->apiFactory->make($site);
            $api->activatePlugin($plugin->file);
            $plugin->update(['is_active' => true]);
            ActivityLogger::pluginActivated($site, $plugin->name);

            return ['success' => true, 'message' => "{$plugin->name} activated."];
        } catch (WordPressApiException|RequestException|\RuntimeException $e) {
            Log::warning("Plugin activation failed: {$plugin->name} on site {$site->name}", [
                'plugin' => $plugin->file,
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Activate failed: '.$this->cleanErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Deactivate a plugin by its model ID.
     *
     * @return array{success: bool, message: string}
     */
    public function deactivatePlugin(Site $site, int $pluginId): array
    {
        /** @var SitePlugin $plugin */
        $plugin = $site->sitePlugins()->findOrFail($pluginId);

        try {
            $api = $this->apiFactory->make($site);
            $api->deactivatePlugin($plugin->file);
            $plugin->update(['is_active' => false]);
            ActivityLogger::pluginDeactivated($site, $plugin->name);

            return ['success' => true, 'message' => "{$plugin->name} deactivated."];
        } catch (WordPressApiException|RequestException|\RuntimeException $e) {
            Log::warning("Plugin deactivation failed: {$plugin->name} on site {$site->name}", [
                'plugin' => $plugin->file,
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Deactivate failed: '.$this->cleanErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Delete a plugin by its model ID.
     *
     * @return array{success: bool, message: string, name: string}
     */
    public function deletePlugin(Site $site, int $pluginId): array
    {
        /** @var SitePlugin $plugin */
        $plugin = $site->sitePlugins()->findOrFail($pluginId);

        try {
            $api = $this->apiFactory->make($site);
            $api->deletePlugin($plugin->file);
            $plugin->delete();
            ActivityLogger::pluginDeleted($site, $plugin->name);

            return ['success' => true, 'message' => "{$plugin->name} deleted.", 'name' => $plugin->name];
        } catch (WordPressApiException|RequestException|\RuntimeException $e) {
            Log::warning("Plugin delete failed: {$plugin->name} on site {$site->name}", [
                'plugin' => $plugin->file,
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Delete failed: '.$this->cleanErrorMessage($e->getMessage()),
                'name' => $plugin->name,
            ];
        }
    }

    /**
     * Activate a theme by its model ID.
     *
     * @return array{success: bool, message: string}
     */
    public function activateTheme(Site $site, int $themeId): array
    {
        /** @var SiteTheme $theme */
        $theme = $site->siteThemes()->findOrFail($themeId);

        try {
            $api = $this->apiFactory->make($site);
            $api->activateTheme($theme->slug);
            ActivityLogger::themeActivated($site, $theme->name);

            return ['success' => true, 'message' => "{$theme->name} activated."];
        } catch (WordPressApiException|RequestException|\RuntimeException $e) {
            Log::warning("Theme activation failed: {$theme->name} on site {$site->name}", [
                'theme' => $theme->slug,
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Activate failed: '.$this->cleanErrorMessage($e->getMessage()),
            ];
        }
    }

    /**
     * Delete a theme by its model ID.
     *
     * @return array{success: bool, message: string, name: string}
     */
    public function deleteTheme(Site $site, int $themeId): array
    {
        /** @var SiteTheme $theme */
        $theme = $site->siteThemes()->findOrFail($themeId);

        try {
            $api = $this->apiFactory->make($site);
            $api->deleteTheme($theme->slug);
            $theme->delete();
            ActivityLogger::themeDeleted($site, $theme->name);

            return ['success' => true, 'message' => "{$theme->name} deleted.", 'name' => $theme->name];
        } catch (WordPressApiException|RequestException|\RuntimeException $e) {
            Log::warning("Theme delete failed: {$theme->name} on site {$site->name}", [
                'theme' => $theme->slug,
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'Delete failed: '.$this->cleanErrorMessage($e->getMessage()),
                'name' => $theme->name,
            ];
        }
    }

    /**
     * Bulk-update a set of plugins by their model IDs.
     * Callers are responsible for running a pre-update backup before calling this.
     *
     * @param  int[]  $ids
     * @return array{success: int, failed: int, results: array, error?: string}
     */
    public function bulkUpdatePlugins(Site $site, array $ids): array
    {
        $plugins = $site->sitePlugins()->whereIn('id', $ids)->where('has_update', true)->get();

        if ($plugins->isEmpty()) {
            return ['success' => 0, 'failed' => 0, 'results' => []];
        }

        $api = $this->apiFactory->make($site);

        try {
            $result = $api->updatePlugins($plugins->pluck('file')->toArray());
            $apiResults = $result['results'] ?? [];
        } catch (WordPressApiException|RequestException|\RuntimeException $e) {
            Log::warning("Bulk plugin update failed on site {$site->name}", [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => 0, 'failed' => count($plugins), 'results' => [], 'error' => $e->getMessage()];
        }

        $success = 0;
        $failed = 0;
        $results = [];

        foreach ($plugins as $plugin) {
            /** @var SitePlugin $plugin */
            $updateResult = $apiResults[$plugin->file] ?? [];
            $wasSuccess = $updateResult['success'] ?? false;

            UpdateLog::create([
                'site_id' => $site->id,
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
                $plugin->update([
                    'version' => $plugin->update_version,
                    'has_update' => false,
                    'update_version' => null,
                ]);
                $results['plugin_'.$plugin->id] = [
                    'success' => true,
                    'message' => "Updated to v{$plugin->update_version}",
                    'version' => $plugin->update_version,
                ];
            } else {
                $failed++;
                $results['plugin_'.$plugin->id] = [
                    'success' => false,
                    'message' => $this->cleanErrorMessage($updateResult['error'] ?? 'Update failed'),
                    'version' => null,
                ];
            }
        }

        return ['success' => $success, 'failed' => $failed, 'results' => $results];
    }

    /**
     * Bulk-update a set of themes by their model IDs.
     * Callers are responsible for running a pre-update backup before calling this.
     *
     * @param  int[]  $ids
     * @return array{success: int, failed: int, results: array, error?: string}
     */
    public function bulkUpdateThemes(Site $site, array $ids): array
    {
        $themes = $site->siteThemes()->whereIn('id', $ids)->where('has_update', true)->get();

        if ($themes->isEmpty()) {
            return ['success' => 0, 'failed' => 0, 'results' => []];
        }

        $api = $this->apiFactory->make($site);

        try {
            $result = $api->updateThemes($themes->pluck('slug')->toArray());
            $apiResults = $result['results'] ?? [];
        } catch (WordPressApiException|RequestException|\RuntimeException $e) {
            Log::warning("Bulk theme update failed on site {$site->name}", [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => 0, 'failed' => count($themes), 'results' => [], 'error' => $e->getMessage()];
        }

        $success = 0;
        $failed = 0;
        $results = [];

        foreach ($themes as $theme) {
            /** @var SiteTheme $theme */
            $updateResult = $apiResults[$theme->slug] ?? [];
            $wasSuccess = $updateResult['success'] ?? false;

            UpdateLog::create([
                'site_id' => $site->id,
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
                $theme->update([
                    'version' => $theme->update_version,
                    'has_update' => false,
                    'update_version' => null,
                ]);
                $results['theme_'.$theme->id] = [
                    'success' => true,
                    'message' => "Updated to v{$theme->update_version}",
                    'version' => $theme->update_version,
                ];
            } else {
                $failed++;
                $results['theme_'.$theme->id] = [
                    'success' => false,
                    'message' => $this->cleanErrorMessage($updateResult['error'] ?? 'Update failed'),
                    'version' => null,
                ];
            }
        }

        return ['success' => $success, 'failed' => $failed, 'results' => $results];
    }

    /**
     * Update WordPress core.
     * Callers are responsible for running a pre-update backup before calling this.
     *
     * @return array{success: bool, message: string}
     */
    public function updateCore(Site $site): array
    {
        try {
            $api = $this->apiFactory->make($site);
            $result = $api->updateCore();
            $success = (bool) ($result['success'] ?? false);
            $error = $this->normalizeError($result['error'] ?? null);

            UpdateLog::create([
                'site_id' => $site->id,
                'user_id' => auth()->id(),
                'type' => 'core',
                'name' => 'WordPress Core',
                'slug' => 'wordpress',
                'from_version' => $site->wp_version,
                'to_version' => $site->core_update_version,
                'success' => $success,
                'error_message' => $error,
                'performed_at' => now(),
            ]);

            // Only claim success when the connector actually confirms it. Reporting
            // a core update as done regardless of the connector's result hid real
            // failures and left sites on outdated (vulnerable) cores (P1-19).
            if (! $success) {
                return [
                    'success' => false,
                    'message' => 'Core update failed: '.($error ?? 'The site reported the update did not complete.'),
                ];
            }

            ActivityLogger::coreUpdated($site, $site->wp_version, $site->core_update_version);

            return ['success' => true, 'message' => 'WordPress core updated successfully.'];
        } catch (WordPressApiException|RequestException|\RuntimeException $e) {
            Log::warning("Core update failed on site {$site->name}", [
                'site_id' => $site->id,
                'error' => $e->getMessage(),
            ]);

            return ['success' => false, 'message' => "Core update failed: {$e->getMessage()}"];
        }
    }

    private function cleanErrorMessage(string $message): string
    {
        return Str::limit(trim(strip_tags($message)), 200);
    }

    /**
     * The connector usually reports an error as a string, but a transport-level
     * failure can surface as an array; normalise both to a stored message.
     */
    private function normalizeError(mixed $error): ?string
    {
        if ($error === null || $error === '') {
            return null;
        }

        $message = is_string($error) ? $error : (string) json_encode($error);

        return $this->cleanErrorMessage($message) ?: null;
    }
}
