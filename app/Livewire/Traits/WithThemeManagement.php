<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Models\SiteTheme;
use App\Services\PluginManagerService;

trait WithThemeManagement
{
    public ?int $confirmingDeleteThemeId = null;

    public ?string $confirmingDeleteThemeName = null;

    public array $confirmingDeleteThemeChildren = [];

    public function updateTheme(int $themeId): void
    {
        $this->authorizeSiteModification($this->site);

        $result = $this->updateSingleTheme($themeId);
        $this->updateResults['theme_'.$themeId] = $result;
    }

    public function updateSingleTheme(int $themeId): array
    {
        $this->authorizeSiteModification($this->site);

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

    public function activateTheme(int $id): void
    {
        $this->authorizeSiteModification($this->site);

        $result = app(PluginManagerService::class)->activateTheme($this->site, $id);
        $this->updateResults['theme_'.$id] = $result + ['version' => null];

        if (! $result['success']) {
            $this->dispatch('notify', type: 'error', message: $result['message']);
        }

        unset($this->themes, $this->themeCounts);
    }

    public function confirmDeleteTheme(int $id): void
    {
        $this->authorizeSiteModification($this->site);

        /** @var SiteTheme $theme */
        $theme = $this->site->siteThemes()->findOrFail($id);
        $this->confirmingDeleteThemeId = $id;
        $this->confirmingDeleteThemeName = $theme->name;

        $childThemes = $this->site->siteThemes()
            ->where('parent_theme', $theme->slug)
            ->pluck('name')
            ->toArray();

        $this->confirmingDeleteThemeChildren = $childThemes;
        $this->dispatch('open-modal-confirm-delete-theme');
    }

    public function deleteThemeById(int $id): void
    {
        $this->authorizeSiteModification($this->site);

        $result = app(PluginManagerService::class)->deleteTheme($this->site, $id);
        $this->dispatch('notify', type: $result['success'] ? 'success' : 'error', message: $result['message']);
        unset($this->themes, $this->themeCounts);
    }

    public function deleteTheme(): void
    {
        $this->authorizeSiteModification($this->site);

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

    public function deleteThemeDirect(int $id): array
    {
        $this->authorizeSiteModification($this->site);

        $result = app(PluginManagerService::class)->deleteTheme($this->site, $id);

        if (! $result['success']) {
            $this->updateResults['theme_'.$id] = ['success' => false, 'message' => $result['message'], 'version' => null];
        }

        unset($this->themes, $this->themeCounts);

        return $result;
    }

    public function bulkUpdateThemes(array $ids): array
    {
        $this->authorizeSiteModification($this->site);

        $this->runPreUpdateBackup();
        $result = app(PluginManagerService::class)->bulkUpdateThemes($this->site, $ids);
        $this->updateResults = array_merge($this->updateResults, $result['results']);
        unset($this->themes, $this->themeCounts);

        return ['success' => $result['success'], 'failed' => $result['failed']];
    }

    public function getUpdatableThemeIds(): array
    {
        return $this->site->siteThemes()->where('has_update', true)->pluck('id')->toArray();
    }

    public function getFilteredThemeIds(): array
    {
        return $this->themes->pluck('id')->toArray();
    }
}
