<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Models\Site;
use App\Models\SiteCrawl;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class CrawlerIndex extends Component
{
    use WithPagination;

    public string $search = '';

    public string $statusFilter = '';

    public ?int $siteFilter = null;

    #[Computed]
    public function sites()
    {
        return Site::query()
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()))
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    #[Computed]
    public function crawls()
    {
        $query = SiteCrawl::with('site')
            ->whereHas('site', fn ($q) => $q->when(! auth()->user()->isAdmin(), fn ($q2) => $q2->where('user_id', auth()->id())));

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->siteFilter) {
            $query->where('site_id', $this->siteFilter);
        }

        if ($this->search) {
            $search = $this->search;
            $query->whereHas('site', fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('url', 'ilike', "%{$search}%"));
        }

        return $query->latest()->paginate(25);
    }

    public function updatedSearch(): void
    {
        $this->search = substr(trim($this->search), 0, 100);
        $this->resetPage();
        unset($this->crawls);
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
        unset($this->crawls);
    }

    public function updatedSiteFilter(): void
    {
        $this->resetPage();
        unset($this->crawls);
    }

    public function deleteCrawl(int $id): void
    {
        $crawl = SiteCrawl::findOrFail($id);
        $this->authorize('delete', $crawl->site);

        if ($crawl->isRunning()) {
            session()->flash('error', __('Cannot delete a running crawl.'));

            return;
        }

        $crawl->delete();
        unset($this->crawls);
        session()->flash('success', __('Crawl deleted.'));
    }

    public function render()
    {
        return view('livewire.seo.crawler-index')
            ->layout('components.layouts.app', ['title' => 'SEO Crawler']);
    }
}
