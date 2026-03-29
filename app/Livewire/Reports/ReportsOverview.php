<?php

declare(strict_types=1);

namespace App\Livewire\Reports;

use App\Livewire\Traits\WithSorting;
use App\Models\Report;
use App\Models\Site;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class ReportsOverview extends Component
{
    use WithPagination, WithSorting;

    protected string $defaultSortBy = 'created_at';

    protected string $defaultSortDir = 'desc';

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $siteFilter = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSiteFilter(): void
    {
        $this->resetPage();
    }

    public function deleteReport(int $reportId): void
    {
        $report = Report::findOrFail($reportId);
        if ($report->file_path) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($report->file_path);
        }
        $report->delete();
    }

    public function render()
    {
        $reports = Report::with(['site.client', 'reportTemplate'])
            ->when($this->search, fn ($q) => $q->where('title', 'ilike', "%{$this->search}%"))
            ->when($this->status !== 'all', fn ($q) => $q->where('status', $this->status))
            ->when($this->siteFilter, fn ($q) => $q->where('site_id', $this->siteFilter))
            ->orderBy(
                in_array($this->sortBy, ['created_at', 'period_start', 'status', 'file_size']) ? $this->sortBy : 'created_at',
                $this->sortDir
            )
            ->paginate(20);

        $sites = Site::orderBy('name')->get(['id', 'name']);

        return view('livewire.reports.reports-overview', [
            'reports' => $reports,
            'sites' => $sites,
        ])->layout('components.layouts.app', ['title' => 'Reports']);
    }
}
