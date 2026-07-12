<?php

declare(strict_types=1);

namespace App\Livewire\Sites;

use App\Livewire\Traits\WithTableFilters;
use App\Models\Site;
use App\Models\Tag;
use App\Services\SettingsService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class SitesList extends Component
{
    use WithTableFilters;

    protected $listeners = ['site-deleted' => '$refresh'];

    #[Url]
    public ?int $tagId = null;

    #[Computed]
    public function availableTags()
    {
        return Tag::orderBy('name')->get();
    }

    public function updatedTagId(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $user = auth()->user();

        $sites = Site::query()
            ->visibleTo($user)
            ->when($this->tagId, fn ($q) => $q->whereHas('tags', fn ($tq) => $tq->where('tags.id', $this->tagId)))
            ->when($this->search, function ($q) {
                $escaped = '%'.$this->escapeLike($this->search).'%';
                $q->where(function ($q) use ($escaped) {
                    $q->where('name', 'ilike', $escaped)
                        ->orWhere('url', 'ilike', $escaped);
                });
            })
            ->when($this->filter !== 'all', function ($q) {
                return match ($this->filter) {
                    'healthy' => $q->where('health_score', '>=', 90)->where('is_up', true),
                    'warning' => $q->where('health_score', '>=', 70)->where('health_score', '<', 90)->where('is_up', true),
                    'critical' => $q->where(function ($q) {
                        $q->where('health_score', '<', 70)->orWhere('is_up', false);
                    }),
                    default => $q,
                };
            })
            ->with('client', 'uptimeMonitor', 'backupConfig', 'performanceMonitor', 'siteStatus', 'analyticsConnection', 'searchConsoleConnection', 'tags')
            ->withCount(['reportSchedules', 'siteUsers', 'sitePlugins'])
            ->paginate((int) app(SettingsService::class)->get('sites_per_page', 16));

        return view('livewire.sites.sites-list', compact('sites'))
            ->layout('components.layouts.app', ['title' => 'Sites']);
    }
}
