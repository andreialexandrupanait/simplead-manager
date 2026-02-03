<?php

namespace App\Livewire\Sites;

use App\Models\Site;
use Livewire\Component;
use Livewire\WithPagination;

class SitesList extends Component
{
    use WithPagination;

    public string $search = '';
    public string $filter = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $sites = Site::query()
            ->when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%")
                ->orWhere('url', 'like', "%{$this->search}%"))
            ->when($this->filter !== 'all', function ($q) {
                return match($this->filter) {
                    'healthy' => $q->where('health_score', '>=', 90)->where('is_up', true),
                    'warning' => $q->where('health_score', '>=', 70)->where('health_score', '<', 90)->where('is_up', true),
                    'critical' => $q->where(function ($q) {
                        $q->where('health_score', '<', 70)->orWhere('is_up', false);
                    }),
                    default => $q,
                };
            })
            ->with('client', 'uptimeMonitor', 'backupConfig', 'performanceMonitor')
            ->latest()
            ->paginate(12);

        return view('livewire.sites.sites-list', compact('sites'))
            ->layout('components.layouts.app', ['title' => 'Sites']);
    }
}