<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Jobs\SyncContentFreshness;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Models\SiteContent;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class SiteContentFreshness extends Component
{
    use WithPagination, WithSiteAuthorization;

    public Site $site;

    public string $filter = 'all';

    public string $search = '';

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function stats(): array
    {
        $base = SiteContent::where('site_id', $this->site->id)->published();

        return [
            'total' => (clone $base)->count(),
            'fresh' => (clone $base)->where('days_since_modified', '<=', 90)->count(),
            'aging' => (clone $base)->where('days_since_modified', '>', 90)->where('days_since_modified', '<=', 180)->count(),
            'stale' => (clone $base)->where('days_since_modified', '>', 180)->count(),
        ];
    }

    public function syncNow(): void
    {
        SyncContentFreshness::dispatch($this->site);
        $this->dispatch('notify', type: 'success', message: 'Content freshness sync queued.');
    }

    public function updatedFilter(): void
    {
        $this->resetPage();
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $contents = SiteContent::where('site_id', $this->site->id)
            ->published()
            ->when($this->filter === 'stale', fn ($q) => $q->where('days_since_modified', '>', 180))
            ->when($this->filter === 'aging', fn ($q) => $q->where('days_since_modified', '>', 90)->where('days_since_modified', '<=', 180))
            ->when($this->filter === 'fresh', fn ($q) => $q->where('days_since_modified', '<=', 90))
            ->when($this->filter === 'posts', fn ($q) => $q->posts())
            ->when($this->filter === 'pages', fn ($q) => $q->pages())
            ->when($this->search, function ($q) {
                $s = '%' . str_replace(['%', '_', '\\'], ['\\%', '\\_', '\\\\'], $this->search) . '%';
                $q->where('title', 'ilike', $s);
            })
            ->orderByDesc('days_since_modified')
            ->paginate(50);

        return view('livewire.sites.detail.site-content-freshness', [
            'contents' => $contents,
        ])->layout('components.layouts.app', [
            'siteContext' => $this->site,
            'title' => $this->site->name . ' — Content Freshness',
        ]);
    }
}
