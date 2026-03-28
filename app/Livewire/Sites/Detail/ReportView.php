<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Report;
use App\Models\Site;
use Livewire\Component;

class ReportView extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public Report $report;

    public string $activeSection = 'overview';

    public function mount(Site $site, Report $report): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;

        if ($report->site_id !== $site->id) {
            abort(404);
        }

        $this->report = $report;
    }

    public function setSection(string $section): void
    {
        $this->activeSection = $section;
    }

    public function getSectionsProperty(): array
    {
        $snapshot = $this->report->data_snapshot ?? [];

        return array_filter([
            'overview' => ! empty($snapshot['overview']) ? 'Overview' : null,
            'uptime' => ! empty($snapshot['uptime']) ? 'Uptime' : null,
            'performance' => ! empty($snapshot['performance']) ? 'Performance' : null,
            'backups' => ! empty($snapshot['backups']) ? 'Backups' : null,
            'security' => ! empty($snapshot['security']) ? 'Security' : null,
            'updates' => ! empty($snapshot['updates']) ? 'Updates' : null,
            'analytics' => ! empty($snapshot['analytics']) ? 'Analytics' : null,
            'search_console' => ! empty($snapshot['search_console']) ? 'Search Console' : null,
        ]);
    }

    public function getSectionDataProperty(): array
    {
        return $this->report->data_snapshot[$this->activeSection] ?? [];
    }

    public function render()
    {
        return view('livewire.sites.detail.report-view')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->report->title,
            ]);
    }
}
