<?php

namespace App\Livewire\Security;

use App\Models\SecurityPreset;
use App\Models\Site;
use App\Services\SecurityPresetService;
use App\Services\SecuritySettingsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class PresetManager extends Component
{
    // Form state
    public bool $showForm = false;

    public ?int $editingId = null;

    public string $presetName = '';

    public string $presetDescription = '';

    public bool $isDefault = false;

    // Apply state
    public ?int $applyingPresetId = null;

    public array $applySiteIds = [];

    // Snapshot from site
    public ?int $snapshotSiteId = null;

    public string $snapshotName = '';

    public function resetForm(): void
    {
        $this->showForm = false;
        $this->editingId = null;
        $this->presetName = '';
        $this->presetDescription = '';
        $this->isDefault = false;
    }

    #[Computed]
    public function presets()
    {
        return SecurityPreset::withCount('sites')->orderBy('name')->get();
    }

    #[Computed]
    public function availableSites()
    {
        return Site::orderBy('name')->get(['id', 'name', 'url']);
    }

    public function editPreset(int $id): void
    {
        $preset = SecurityPreset::findOrFail($id);
        $this->editingId = $preset->id;
        $this->presetName = $preset->name;
        $this->presetDescription = $preset->description ?? '';
        $this->isDefault = $preset->is_default;
        $this->showForm = true;
    }

    public function savePreset(): void
    {
        $this->validate([
            'presetName' => 'required|string|max:255',
            'presetDescription' => 'nullable|string|max:1000',
        ]);

        if ($this->editingId) {
            $preset = SecurityPreset::findOrFail($this->editingId);
            $preset->update([
                'name' => $this->presetName,
                'description' => $this->presetDescription,
                'is_default' => $this->isDefault,
            ]);

            // Increment version if settings or default changed
            app(SecurityPresetService::class)->incrementVersion($preset);

            session()->flash('preset-success', "Preset '{$preset->name}' updated (v{$preset->fresh()->version}).");
        } else {
            SecurityPreset::create([
                'name' => $this->presetName,
                'description' => $this->presetDescription,
                'settings' => [],
                'is_default' => $this->isDefault,
                'created_by' => auth()->id(),
            ]);

            session()->flash('preset-success', "Preset '{$this->presetName}' created.");
        }

        $this->resetForm();
        unset($this->presets);
    }

    public function deletePreset(int $id): void
    {
        $preset = SecurityPreset::findOrFail($id);
        $preset->delete();
        unset($this->presets);
        session()->flash('preset-success', "Preset '{$preset->name}' deleted.");
    }

    public function startApply(int $presetId): void
    {
        $this->applyingPresetId = $presetId;
        $this->applySiteIds = [];
    }

    public function cancelApply(): void
    {
        $this->applyingPresetId = null;
        $this->applySiteIds = [];
    }

    public function applyToSites(): void
    {
        if (! $this->applyingPresetId || empty($this->applySiteIds)) {
            return;
        }

        $preset = SecurityPreset::findOrFail($this->applyingPresetId);
        $sites = Site::whereIn('id', $this->applySiteIds)->get();

        app(SecuritySettingsService::class)->applyPreset($preset, $sites);

        session()->flash('preset-success', "Preset '{$preset->name}' applied to {$sites->count()} site(s).");
        $this->cancelApply();
        unset($this->presets);
    }

    public function createFromSite(): void
    {
        $this->validate([
            'snapshotSiteId' => 'required|exists:sites,id',
            'snapshotName' => 'required|string|max:255',
        ]);

        $site = Site::findOrFail($this->snapshotSiteId);
        app(SecurityPresetService::class)->createFromSite($site, $this->snapshotName);

        $this->snapshotSiteId = null;
        $this->snapshotName = '';
        unset($this->presets);
        session()->flash('preset-success', "Preset created from '{$site->name}'.");
    }

    public function render()
    {
        return view('livewire.security.preset-manager')
            ->layout('components.layouts.app', [
                'title' => 'Security Presets',
            ]);
    }
}
