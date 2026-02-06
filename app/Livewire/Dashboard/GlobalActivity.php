<?php

namespace App\Livewire\Dashboard;

use App\Models\ActivityLog;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class GlobalActivity extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $typeFilter = 'all';

    #[Url]
    public string $severityFilter = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingTypeFilter(): void
    {
        $this->resetPage();
    }

    public function updatingSeverityFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function activities()
    {
        $query = ActivityLog::with('site')
            ->whereHas('site')
            ->orderByDesc('created_at');

        if ($this->typeFilter !== 'all') {
            $query->ofType($this->typeFilter);
        }

        if ($this->severityFilter !== 'all') {
            $query->ofSeverity($this->severityFilter);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('title', 'like', "%{$this->search}%")
                    ->orWhere('description', 'like', "%{$this->search}%")
                    ->orWhereHas('site', fn ($sq) => $sq->where('name', 'like', "%{$this->search}%"));
            });
        }

        return $query->paginate(30);
    }

    public function render()
    {
        return view('livewire.dashboard.global-activity')
            ->layout('components.layouts.app', ['title' => 'Activity']);
    }
}
