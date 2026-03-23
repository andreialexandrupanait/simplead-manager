<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Models\MaintenancePlan;
use App\Models\SecurityPreset;
use App\Models\Site;
use App\Services\BulkSettingsCopyService;
use App\Services\ModuleConfigService;
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
    public string $operation = ''; // copy_from_site, security_preset, module_plan

    // Step 3: Configuration
    public ?int $sourceSiteId = null;

    public bool $copySecuritySettings = true;

    public bool $copyTweakSettings = true;

    public bool $copyModuleConfig = true;

    public ?int $securityPresetId = null;

    public ?int $modulePlanId = null;

    #[Computed]
    public function sites()
    {
        $query = Site::query()
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
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
    public function modulePlans()
    {
        return MaintenancePlan::with('planModules')->orderBy('name')->get();
    }

    #[Computed]
    public function sourceSites()
    {
        return Site::query()
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
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

        if ($step === 3 && ! $this->operation) {
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
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()));
        $targets = $scopedQuery->whereIn('id', $this->selectedSiteIds)->get();

        if ($targets->isEmpty()) {
            session()->flash('bulk-error', 'No valid target sites found.');

            return;
        }

        match ($this->operation) {
            'copy_from_site' => $this->applyCopyFromSite($targets),
            'security_preset' => $this->applySecurityPreset($targets),
            'module_plan' => $this->applyModulePlan($targets),
            default => session()->flash('bulk-error', 'Invalid operation.'),
        };
    }

    private function applyCopyFromSite($targets): void
    {
        if (! $this->sourceSiteId) {
            session()->flash('bulk-error', 'Please select a source site.');

            return;
        }

        if (! $this->copySecuritySettings && ! $this->copyTweakSettings && ! $this->copyModuleConfig) {
            session()->flash('bulk-error', 'Please select at least one setting type to copy.');

            return;
        }

        $source = Site::find($this->sourceSiteId);
        if (! $source) {
            session()->flash('bulk-error', 'Source site not found.');

            return;
        }

        // Remove source from targets if selected
        $targets = $targets->reject(fn ($site) => $site->id === $source->id);

        $service = app(BulkSettingsCopyService::class);
        $pushed = 0;
        $total = $targets->count();

        if ($this->copySecuritySettings) {
            $result = $service->copySecuritySettings($source, $targets);
            $pushed = max($pushed, $result['pushed']);
        }
        if ($this->copyTweakSettings) {
            $result = $service->copyTweakSettings($source, $targets);
            $pushed = max($pushed, $result['pushed']);
        }
        if ($this->copyModuleConfig) {
            $service->copyModuleConfig($source, $targets);
        }

        $this->resetState();

        $message = "Settings saved from {$source->name} to {$total} site(s).";
        if ($pushed > 0) {
            $message .= " Pushing to {$pushed} connected site(s).";
        }
        $disconnected = $total - $pushed;
        if ($disconnected > 0) {
            $message .= " {$disconnected} disconnected site(s) will receive settings when connected.";
        }
        session()->flash('bulk-success', $message);
    }

    private function applySecurityPreset($targets): void
    {
        if (! $this->securityPresetId) {
            session()->flash('bulk-error', 'Please select a security preset.');

            return;
        }

        $preset = SecurityPreset::find($this->securityPresetId);
        if (! $preset) {
            session()->flash('bulk-error', 'Preset not found.');

            return;
        }

        $result = app(BulkSettingsCopyService::class)->applySecurityPreset($preset, $targets);

        $this->resetState();

        $message = "Security preset '{$preset->name}' saved to {$result['total']} site(s).";
        if ($result['pushed'] > 0) {
            $message .= " Pushing to {$result['pushed']} connected site(s).";
        }
        $disconnected = $result['total'] - $result['pushed'];
        if ($disconnected > 0) {
            $message .= " {$disconnected} disconnected site(s) will receive settings when connected.";
        }
        session()->flash('bulk-success', $message);
    }

    private function applyModulePlan($targets): void
    {
        if (! $this->modulePlanId) {
            session()->flash('bulk-error', 'Please select a maintenance plan.');

            return;
        }

        $plan = MaintenancePlan::with('planModules')->find($this->modulePlanId);
        if (! $plan) {
            session()->flash('bulk-error', 'Plan not found.');

            return;
        }

        $moduleConfigService = app(ModuleConfigService::class);
        foreach ($targets as $target) {
            $moduleConfigService->applyPlan($target, $plan);
        }

        $this->resetState();
        session()->flash('bulk-success', "Maintenance plan '{$plan->name}' applied to {$targets->count()} site(s).");
    }

    private function resetState(): void
    {
        $this->step = 1;
        $this->selectedSiteIds = [];
        $this->selectAll = false;
        $this->operation = '';
        $this->sourceSiteId = null;
        $this->securityPresetId = null;
        $this->modulePlanId = null;
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
