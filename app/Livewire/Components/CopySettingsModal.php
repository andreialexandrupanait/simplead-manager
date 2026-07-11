<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\BulkSettingsCopyService;
use Livewire\Component;

class CopySettingsModal extends Component
{
    use WithSiteAuthorization;

    public Site $sourceSite;

    public bool $showSecurityOption = false;

    public bool $showTweaksOption = false;

    public bool $showModulesOption = false;

    public bool $copySecuritySettings = false;

    public bool $copyTweakSettings = false;

    public bool $copyModuleConfig = false;

    public array $selectedSiteIds = [];

    public bool $selectAll = false;

    public function mount(Site $sourceSite): void
    {
        // The source site's settings are read and pushed elsewhere — the acting
        // user must at least be able to access it.
        $this->authorizeSiteAccess($sourceSite);

        // Auto-enable the options that are shown
        $this->copySecuritySettings = $this->showSecurityOption;
        $this->copyTweakSettings = $this->showTweaksOption;
        $this->copyModuleConfig = $this->showModulesOption;
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedSiteIds = $this->getAvailableSites()->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        } else {
            $this->selectedSiteIds = [];
        }
    }

    public function apply(): void
    {
        // Read-only users cannot push settings anywhere.
        if (auth()->user()->isViewer()) {
            abort(403, 'Viewers cannot modify sites.');
        }

        if (empty($this->selectedSiteIds)) {
            session()->flash('copy-error', 'Please select at least one target site.');

            return;
        }

        if (! $this->copySecuritySettings && ! $this->copyTweakSettings && ! $this->copyModuleConfig) {
            session()->flash('copy-error', 'Please select at least one setting type to copy.');

            return;
        }

        // Re-scope the target IDs server-side to the sites the acting user may
        // actually reach — a crafted request cannot push config cross-tenant.
        $targets = $this->getAvailableSites()
            ->whereIn('id', $this->selectedSiteIds)
            ->values();

        if ($targets->isEmpty()) {
            session()->flash('copy-error', 'Please select at least one target site.');

            return;
        }
        $service = app(BulkSettingsCopyService::class);
        $pushed = 0;
        $total = $targets->count();

        if ($this->copySecuritySettings && $this->showSecurityOption) {
            $result = $service->copySecuritySettings($this->sourceSite, $targets);
            $pushed = max($pushed, $result['pushed']);
        }

        if ($this->copyTweakSettings && $this->showTweaksOption) {
            $result = $service->copyTweakSettings($this->sourceSite, $targets);
            $pushed = max($pushed, $result['pushed']);
        }

        if ($this->copyModuleConfig && $this->showModulesOption) {
            $service->copyModuleConfig($this->sourceSite, $targets);
        }

        $this->selectedSiteIds = [];
        $this->selectAll = false;

        $this->dispatch('close-modal-copy-settings');

        $message = "Settings saved to {$total} site(s).";
        if ($pushed > 0) {
            $message .= " Pushing to {$pushed} connected site(s).";
        }
        $disconnected = $total - $pushed;
        if ($disconnected > 0) {
            $message .= " {$disconnected} disconnected site(s) will receive settings when connected.";
        }
        session()->flash('success', $message);
    }

    public function getAvailableSites()
    {
        $query = Site::where('id', '!=', $this->sourceSite->id);

        $user = auth()->user();
        if (! $user->isAdmin()) {
            $query->where('user_id', $user->id);
        }

        return $query->get();
    }

    public function render()
    {
        return view('livewire.components.copy-settings-modal', [
            'availableSites' => $this->getAvailableSites(),
        ]);
    }
}
