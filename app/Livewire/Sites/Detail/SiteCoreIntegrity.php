<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\CheckCoreFileIntegrity;
use App\Models\CoreFileCheck;
use App\Models\Site;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class SiteCoreIntegrity extends Component
{
    use WithPagination;

    public Site $site;

    public ?string $expandedSection = null;

    public function mount(Site $site): void
    {
        $this->site = $site;
    }

    #[Computed]
    public function latestCheck(): ?CoreFileCheck
    {
        return $this->site->latestCoreFileCheck;
    }

    #[Computed]
    public function checkHistory()
    {
        return $this->site->coreFileChecks()
            ->orderByDesc('checked_at')
            ->limit(10)
            ->get();
    }

    public function toggleSection(string $section): void
    {
        $this->expandedSection = $this->expandedSection === $section ? null : $section;
    }

    public function runCheck(): void
    {
        CheckCoreFileIntegrity::dispatch($this->site);
        session()->flash('integrity-success', 'Core file integrity check has been queued. Results will appear shortly.');
        unset($this->latestCheck, $this->checkHistory);
    }

    public function render()
    {
        return view('livewire.sites.detail.site-core-integrity')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Core Integrity',
            ]);
    }
}
