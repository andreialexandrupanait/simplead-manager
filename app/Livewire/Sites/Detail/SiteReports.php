<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Livewire\Traits\WithJobTracking;
use App\Livewire\Traits\WithReportDistribution;
use App\Livewire\Traits\WithReportGeneration;
use App\Livewire\Traits\WithReportScheduling;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Livewire\Traits\WithSorting;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Services\ReportManagementService;
use Livewire\Component;
use Livewire\WithPagination;

class SiteReports extends Component
{
    use WithJobTracking, WithPagination, WithReportDistribution, WithReportGeneration, WithReportScheduling, WithSiteAuthorization, WithSorting;

    protected string $defaultSortBy = 'created_at';

    protected string $defaultSortDir = 'desc';

    public Site $site;

    public ?int $selectedTemplateId = null;

    protected function jobTrackingKeys(): array
    {
        return [
            'generate' => 'report-generate-'.$this->site->id,
        ];
    }

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        $this->initJobTracking();

        $this->selectedTemplateId = $site->report_template_id
            ?? ReportTemplate::where('is_default', true)->value('id')
            ?? ReportTemplate::value('id');
        $this->scheduleTemplateId = $this->selectedTemplateId;
    }

    public function updateSiteTemplate(): void
    {
        $this->authorizeSiteModification($this->site);
        $this->site->update(['report_template_id' => $this->selectedTemplateId]);
    }

    public function deleteReport(int $reportId): void
    {
        $this->authorizeSiteModification($this->site);
        app(ReportManagementService::class)->deleteReports([$reportId], $this->site);
        session()->flash('report-success', 'Report deleted.');
        $this->resetPage();
    }

    protected function onJobFinished(string $jobName, array $data): void
    {
        // Report list will auto-refresh on next render since it's a query in render()
    }

    public function render()
    {
        $schedule = $this->site->reportSchedules()->with('reportTemplate')->first();
        $reports = $this->site->reports()
            ->with('reportTemplate')
            ->orderBy(
                in_array($this->sortBy, ['created_at', 'period_start', 'status', 'file_size']) ? $this->sortBy : 'created_at',
                $this->sortDir
            )
            ->paginate(15);
        $templates = ReportTemplate::orderBy('name')->get();

        $portalToken = $this->site->client?->portal_enabled ? $this->site->client->portal_token : null;

        return view('livewire.sites.detail.site-reports', [
            'schedule' => $schedule,
            'reports' => $reports,
            'templates' => $templates,
            'portalToken' => $portalToken,
        ])
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Reports',
            ]);
    }
}
