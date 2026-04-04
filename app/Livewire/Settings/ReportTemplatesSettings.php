<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Livewire\Traits\WithTemplateForm;
use App\Livewire\Traits\WithTemplateSiteAssignment;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Site;
use Livewire\Component;
use Livewire\WithPagination;

class ReportTemplatesSettings extends Component
{
    use WithPagination;
    use WithTemplateForm;
    use WithTemplateSiteAssignment;

    public function render()
    {
        $templates = ReportTemplate::withCount(['schedules', 'sites'])->orderBy('name')->simplePaginate(15);

        // Get earliest next_run_at per template for active schedules
        $nextRunDates = ReportSchedule::query()
            ->where('is_active', true)
            ->whereNotNull('next_run_at')
            ->whereIn('report_template_id', $templates->pluck('id'))
            ->selectRaw('report_template_id, MIN(next_run_at) as next_run_at')
            ->groupBy('report_template_id')
            ->pluck('next_run_at', 'report_template_id')
            ->map(fn ($date) => \Carbon\Carbon::parse($date));

        $assignSites = [];
        if ($this->showAssignSitesModal) {
            $query = Site::select('id', 'name', 'url', 'report_template_id')->orderBy('name');
            if ($this->siteSearch !== '') {
                $query->where(function ($q) {
                    $q->where('name', 'ilike', '%'.$this->siteSearch.'%')
                        ->orWhere('url', 'ilike', '%'.$this->siteSearch.'%');
                });
            }
            $assignSites = $query->get();
        }

        return view('livewire.settings.report-templates-settings', [
            'templates' => $templates,
            'nextRunDates' => $nextRunDates,
            'sectionSubOptions' => static::sectionSubOptions(),
            'assignSites' => $assignSites,
        ])->layout('components.layouts.app', ['title' => 'Report Templates']);
    }
}
