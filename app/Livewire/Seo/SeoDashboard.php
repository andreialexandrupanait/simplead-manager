<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Livewire\Traits\WithSorting;
use App\Models\Site;
use App\Services\SeoDashboardService;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class SeoDashboard extends Component
{
    use WithPagination, WithSorting;

    protected string $defaultSortBy = 'name';

    protected string $defaultSortDir = 'asc';

    public string $search = '';

    public string $scoreFilter = '';

    #[Computed]
    public function kpis(): array
    {
        return app(SeoDashboardService::class)->getKpis();
    }

    #[Computed]
    public function activityFeed()
    {
        return app(SeoDashboardService::class)->getActivityFeed();
    }

    #[Computed]
    public function sites()
    {
        $query = Site::query()
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->with(['latestSeoAudit', 'latestSiteCrawl', 'seoMonitor'])
            ->withCount([
                'seoIssues as critical_issues_count' => fn (Builder $q) => $q->whereNull('resolved_at')->where('severity', 'critical'),
                'trackedKeywords',
            ]);

        if ($this->search) {
            $search = $this->search;
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('url', 'ilike', "%{$search}%"));
        }

        if ($this->scoreFilter === 'critical') {
            $query->whereHas('latestSeoAudit', fn ($q) => $q->where('score', '<', 50));
        } elseif ($this->scoreFilter === 'warning') {
            $query->whereHas('latestSeoAudit', fn ($q) => $q->where('score', '>=', 50)->where('score', '<', 80));
        } elseif ($this->scoreFilter === 'good') {
            $query->whereHas('latestSeoAudit', fn ($q) => $q->where('score', '>=', 80));
        }

        $sortable = ['name'];
        $sort = in_array($this->sortBy, $sortable) ? $this->sortBy : 'name';

        return $query->orderBy($sort, $this->sortDir)->paginate(50);
    }

    public function updatedSearch(): void
    {
        $this->search = substr(trim($this->search), 0, 100);
        $this->resetPage();
        unset($this->sites);
    }

    public function updatedScoreFilter(): void
    {
        $this->resetPage();
        unset($this->sites);
    }

    public function render()
    {
        return view('livewire.seo.seo-dashboard')
            ->layout('components.layouts.app', ['title' => 'SEO Dashboard']);
    }
}
