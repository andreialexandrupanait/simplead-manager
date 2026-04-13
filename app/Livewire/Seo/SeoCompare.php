<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Models\Site;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class SeoCompare extends Component
{
    #[Url] public array $selectedIds = [];
    public string $search = '';

    #[Computed]
    public function availableSites(): \Illuminate\Support\Collection
    {
        $q = Site::query()->whereNull('deleted_at')
            ->whereHas('seoAudits', fn ($q) => $q->completed())
            ->with('latestSeoAudit');

        if ($this->search !== '') {
            $q->where(fn ($q) => $q->where('name', 'ilike', "%{$this->search}%")->orWhere('url', 'ilike', "%{$this->search}%"));
        }

        return $q->limit(20)->get();
    }

    #[Computed]
    public function comparisonData(): array
    {
        if (count($this->selectedIds) < 2) {
            return [];
        }

        $sites = Site::whereIn('id', $this->selectedIds)
            ->with('latestSeoAudit')
            ->get()
            ->filter(fn ($s) => $s->latestSeoAudit !== null);

        if ($sites->count() < 2) {
            return [];
        }

        return $sites->map(function ($site) {
            $audit = $site->latestSeoAudit;
            $cats = $audit->category_scores ?? [];

            return [
                'id' => $site->id,
                'name' => $site->name,
                'domain' => $site->domain,
                'is_prospect' => $site->is_prospect,
                'score' => $audit->score,
                'technical' => $cats['technical'] ?? 0,
                'on_page' => $cats['on_page'] ?? 0,
                'performance' => $cats['performance'] ?? 0,
                'other' => $cats['other'] ?? 0,
                'pages_crawled' => $audit->pages_crawled,
                'critical' => $audit->critical_count,
                'high' => $audit->high_count,
                'medium' => $audit->medium_count,
                'total_issues' => $audit->totalIssues(),
                'scanned_at' => $audit->scanned_at?->diffForHumans(),
            ];
        })->values()->toArray();
    }

    public function toggleSite(int $id): void
    {
        if (in_array($id, $this->selectedIds)) {
            $this->selectedIds = array_values(array_diff($this->selectedIds, [$id]));
        } elseif (count($this->selectedIds) < 4) {
            $this->selectedIds[] = $id;
        } else {
            $this->dispatch('notify', type: 'warning', message: 'Maximum 4 sites for comparison.');
        }
        unset($this->comparisonData);
    }

    public function render()
    {
        return view('livewire.seo.seo-compare')->layout('components.layouts.app');
    }
}
