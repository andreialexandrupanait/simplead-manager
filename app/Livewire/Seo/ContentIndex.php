<?php

declare(strict_types=1);

namespace App\Livewire\Seo;

use App\Models\SeoContent;
use App\Models\Site;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class ContentIndex extends Component
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
    public function contents()
    {
        $query = SeoContent::with(['site', 'user'])
            ->where('user_id', auth()->id())
            ->when(auth()->user()->isAdmin(), fn ($q) => $q->orWhereNotNull('id'));

        if ($this->statusFilter) {
            $query->where('status', $this->statusFilter);
        }

        if ($this->siteFilter) {
            $query->where('site_id', $this->siteFilter);
        }

        if ($this->search) {
            $search = $this->search;
            $query->where(fn ($q) => $q->where('title', 'ilike', "%{$search}%")->orWhere('target_keyword', 'ilike', "%{$search}%"));
        }

        return $query->latest()->paginate(25);
    }

    public function updatedSearch(): void
    {
        $this->search = substr(trim($this->search), 0, 100);
        $this->resetPage();
        unset($this->contents);
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
        unset($this->contents);
    }

    public function updatedSiteFilter(): void
    {
        $this->resetPage();
        unset($this->contents);
    }

    public function deleteContent(int $id): void
    {
        $content = SeoContent::where('user_id', auth()->id())->findOrFail($id);
        $content->delete();
        unset($this->contents);
        session()->flash('success', __('Article deleted.'));
    }

    public function render()
    {
        return view('livewire.seo.content-index')
            ->layout('components.layouts.app', ['title' => 'SEO Content AI']);
    }
}
