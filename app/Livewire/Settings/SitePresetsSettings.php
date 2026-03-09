<?php

namespace App\Livewire\Settings;

use App\Models\SitePreset;
use App\Models\SitePresetModule;
use App\Services\ModuleConfigService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SitePresetsSettings extends Component
{
    // Form state
    public bool $showForm = false;
    public ?int $editingId = null;
    public string $presetName = '';
    public string $presetDescription = '';
    public array $presetModules = [];
    public bool $presetIsDefault = false;
    public int $presetSortOrder = 0;

    // Delete confirmation
    public ?int $confirmDeleteId = null;

    public function mount(): void
    {
        $this->resetForm();
    }

    #[Computed]
    public function presets()
    {
        return SitePreset::with('presetModules')->withCount('sites')->orderBy('sort_order')->get();
    }

    #[Computed]
    public function moduleKeys(): array
    {
        return ModuleConfigService::getModuleKeys();
    }

    #[Computed]
    public function moduleLabels(): array
    {
        return [
            'uptime' => 'Uptime Monitoring',
            'backup' => 'Backups',
            'ssl' => 'SSL Monitoring',
            'performance' => 'Performance Tests',
            'security' => 'Security Scans',
            'analytics' => 'Google Analytics',
            'search_console' => 'Search Console',
            'cloudflare' => 'Cloudflare',
            'database_cleanup' => 'Database Cleanup',
        ];
    }

    public function openCreate(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function openEdit(int $id): void
    {
        $preset = SitePreset::with('presetModules')->findOrFail($id);

        $this->editingId = $preset->id;
        $this->presetName = $preset->name;
        $this->presetDescription = $preset->description ?? '';
        $this->presetIsDefault = $preset->is_default;
        $this->presetSortOrder = $preset->sort_order;
        $this->showForm = true;

        // Build presetModules array from relationship rows
        $this->presetModules = [];
        foreach (ModuleConfigService::getModuleKeys() as $key) {
            $this->presetModules[$key] = ['enabled' => false];
        }
        foreach ($preset->presetModules as $mod) {
            $this->presetModules[$mod->module_key] = ['enabled' => $mod->is_enabled];
        }
    }

    public function save(): void
    {
        $this->validate([
            'presetName' => 'required|string|max:255',
            'presetDescription' => 'nullable|string|max:500',
            'presetSortOrder' => 'required|integer|min:0',
        ]);

        // If setting as default, remove default from others
        if ($this->presetIsDefault) {
            SitePreset::where('is_default', true)
                ->when($this->editingId, fn ($q) => $q->where('id', '!=', $this->editingId))
                ->update(['is_default' => false]);
        }

        $preset = SitePreset::updateOrCreate(
            ['id' => $this->editingId],
            [
                'name' => $this->presetName,
                'description' => $this->presetDescription,
                'is_default' => $this->presetIsDefault,
                'sort_order' => $this->presetSortOrder,
            ]
        );

        // Sync module rows
        foreach ($this->presetModules as $key => $config) {
            SitePresetModule::updateOrCreate(
                ['site_preset_id' => $preset->id, 'module_key' => $key],
                ['is_enabled' => $config['enabled'] ?? false]
            );
        }

        $wasEditing = $this->editingId;
        $this->showForm = false;
        $this->resetForm();
        unset($this->presets);
        $this->dispatch('notify', type: 'success', message: $wasEditing ? 'Preset updated.' : 'Preset created.');
    }

    public function confirmDelete(int $id): void
    {
        $this->confirmDeleteId = $id;
    }

    public function delete(): void
    {
        if (!$this->confirmDeleteId) return;

        $preset = SitePreset::withCount('sites')->findOrFail($this->confirmDeleteId);

        if ($preset->sites_count > 0) {
            $this->dispatch('notify', type: 'error', message: "Cannot delete \"{$preset->name}\" — {$preset->sites_count} site(s) are using it.");
            $this->confirmDeleteId = null;
            return;
        }

        $preset->delete();
        $this->confirmDeleteId = null;
        unset($this->presets);
        $this->dispatch('notify', type: 'success', message: 'Preset deleted.');
    }

    public function cancelDelete(): void
    {
        $this->confirmDeleteId = null;
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function toggleModuleInForm(string $module): void
    {
        $current = $this->presetModules[$module]['enabled'] ?? false;
        $this->presetModules[$module]['enabled'] = !$current;
    }

    private function resetForm(): void
    {
        $this->editingId = null;
        $this->presetName = '';
        $this->presetDescription = '';
        $this->presetIsDefault = false;
        $this->presetSortOrder = 0;
        $this->resetValidation();

        // Initialize all modules as disabled
        $this->presetModules = [];
        foreach (ModuleConfigService::getModuleKeys() as $key) {
            $this->presetModules[$key] = ['enabled' => false];
        }
    }

    public function render()
    {
        return view('livewire.settings.site-presets-settings')
            ->layout('components.layouts.app', ['title' => 'Site Presets']);
    }
}
