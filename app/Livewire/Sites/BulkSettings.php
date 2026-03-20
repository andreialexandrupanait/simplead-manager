<?php

namespace App\Livewire\Sites;

use App\Models\SecurityPreset;
use App\Models\Site;
use App\Models\SitePreset;
use App\Services\BulkSettingsCopyService;
use App\Services\ModuleConfigService;
use App\Services\SecuritySettingsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class BulkSettings extends Component
{
    // Step tracking
    public int $step = 1;

    // Step 1: Site selection
    public string $search = '';
    public array $selectedSiteIds = [];
    public bool $selectAll = false;

    // Step 2: Operation
    public string $operation = ''; // copy_from_site, security_preset, module_preset

    // Step 3: Configuration
    public ?int $sourceSiteId = null;
    public bool $copySecuritySettings = true;
    public bool $copyTweakSettings = true;
    public bool $copyModuleConfig = true;

    public ?int $securityPresetId = null;
    public ?int $modulePresetId = null;

    #[Computed]
    public function sites()
    {
        $query = Site::query()
            ->when(!auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->orderBy('name');

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('name', 'ilike', "%{$this->search}%")
                    ->orWhere('url', 'ilike', "%{$this->search}%");
            });
        }

        return $query->get();
    }

    #[Computed]
    public function securityPresets()
    {
        return SecurityPreset::orderBy('name')->get();
    }

    #[Computed]
    public function modulePresets()
    {
        return SitePreset::with('presetModules')->orderBy('name')->get();
    }

    #[Computed]
    public function sourceSites()
    {
        return Site::query()
            ->when(!auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->orderBy('name')
            ->get();
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedSiteIds = $this->sites->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        } else {
            $this->selectedSiteIds = [];
        }
    }

    public function updatedSearch(): void
    {
        $this->search = substr(trim($this->search), 0, 100);
        unset($this->sites);
    }

    public function goToStep(int $step): void
    {
        if ($step === 2 && empty($this->selectedSiteIds)) {
            session()->flash('bulk-error', 'Please select at least one site.');
            return;
        }

        if ($step === 3 && !$this->operation) {
            session()->flash('bulk-error', 'Please choose an operation.');
            return;
        }

        $this->step = $step;
    }

    public function apply(): void
    {
        if (empty($this->selectedSiteIds)) {
            session()->flash('bulk-error', 'No sites selected.');
            return;
        }

        $scopedQuery = Site::query()
            ->when(!auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()));
        $targets = $scopedQuery->whereIn('id', $this->selectedSiteIds)->get();

        if ($targets->isEmpty()) {
            session()->flash('bulk-error', 'No valid target sites found.');
            return;
        }

        match ($this->operation) {
            'copy_from_site' => $this->applyCopyFromSite($targets),
            'security_preset' => $this->applySecurityPreset($targets),
            'module_preset' => $this->applyModulePreset($targets),
            default => session()->flash('bulk-error', 'Invalid operation.'),
        };
    }

    private function applyCopyFromSite($targets): void
    {
        if (!$this->sourceSiteId) {
            session()->flash('bulk-error', 'Please select a source site.');
            return;
        }

        if (!$this->copySecuritySettings && !$this->copyTweakSettings && !$this->copyModuleConfig) {
            session()->flash('bulk-error', 'Please select at least one setting type to copy.');
            return;
        }

        $source = Site::find($this->sourceSiteId);
        if (!$source) {
            session()->flash('bulk-error', 'Source site not found.');
            return;
        }

        // Remove source from targets if selected
        $targets = $targets->reject(fn ($site) => $site->id === $source->id);

        $service = app(BulkSettingsCopyService::class);

        if ($this->copySecuritySettings) {
            $service->copySecuritySettings($source, $targets);
        }
        if ($this->copyTweakSettings) {
            $service->copyTweakSettings($source, $targets);
        }
        if ($this->copyModuleConfig) {
            $service->copyModuleConfig($source, $targets);
        }

        $this->resetState();
        session()->flash('bulk-success', "Settings copied from {$source->name} to {$targets->count()} site(s). Changes will be pushed shortly.");
    }

    private function applySecurityPreset($targets): void
    {
        if (!$this->securityPresetId) {
            session()->flash('bulk-error', 'Please select a security preset.');
            return;
        }

        $preset = SecurityPreset::find($this->securityPresetId);
        if (!$preset) {
            session()->flash('bulk-error', 'Preset not found.');
            return;
        }

        app(SecuritySettingsService::class)->applyPreset($preset, $targets);

        $this->resetState();
        session()->flash('bulk-success', "Security preset '{$preset->name}' applied to {$targets->count()} site(s).");
    }

    private function applyModulePreset($targets): void
    {
        if (!$this->modulePresetId) {
            session()->flash('bulk-error', 'Please select a module preset.');
            return;
        }

        $preset = SitePreset::with('presetModules')->find($this->modulePresetId);
        if (!$preset) {
            session()->flash('bulk-error', 'Preset not found.');
            return;
        }

        $moduleConfigService = app(ModuleConfigService::class);
        foreach ($targets as $target) {
            $moduleConfigService->applyPreset($target, $preset);
        }

        $this->resetState();
        session()->flash('bulk-success', "Module preset '{$preset->name}' applied to {$targets->count()} site(s).");
    }

    private function resetState(): void
    {
        $this->step = 1;
        $this->selectedSiteIds = [];
        $this->selectAll = false;
        $this->operation = '';
        $this->sourceSiteId = null;
        $this->securityPresetId = null;
        $this->modulePresetId = null;
        $this->copySecuritySettings = true;
        $this->copyTweakSettings = true;
        $this->copyModuleConfig = true;
        unset($this->sites);
    }

    public function render()
    {
        return view('livewire.sites.bulk-settings')
            ->layout('components.layouts.app', [
                'title' => 'Bulk Settings',
            ]);
    }
}
