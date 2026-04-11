<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Seo;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\SeoAgentService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SeoAgent extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public ?array $report = null;

    public bool $isAnalyzing = false;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    public function analyze(): void
    {
        $this->isAnalyzing = true;
        $this->report = app(SeoAgentService::class)->analyze($this->site);
        $this->isAnalyzing = false;
    }

    public function render()
    {
        return view('livewire.sites.detail.seo.seo-agent')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — SEO Agent',
            ]);
    }
}
