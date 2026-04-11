<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Seo;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\SeoHealthReportService;
use Livewire\Component;

class SeoHealthReport extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public ?array $report = null;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    public function analyze(): void
    {
        $this->report = app(SeoHealthReportService::class)->analyze($this->site);
    }

    public function render()
    {
        return view('livewire.sites.detail.seo.seo-health-report')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — SEO Health Report',
            ]);
    }
}
