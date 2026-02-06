<?php

namespace App\Livewire\Dashboard;

use App\Models\ErrorLog;
use App\Models\Site;
use App\Services\ErrorLogService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class GlobalErrors extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $siteFilter = 'all';

    #[Url]
    public string $levelFilter = 'all';

    #[Url]
    public bool $showResolved = false;

    public ?int $expandedId = null;

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingSiteFilter(): void
    {
        $this->resetPage();
    }

    public function updatingLevelFilter(): void
    {
        $this->resetPage();
    }

    public function updatingShowResolved(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function errors()
    {
        $query = ErrorLog::with('site')
            ->whereHas('site')
            ->orderByDesc('last_seen_at');

        if (!$this->showResolved) {
            $query->unresolved();
        }

        if ($this->siteFilter !== 'all') {
            $query->where('site_id', $this->siteFilter);
        }

        if ($this->levelFilter !== 'all') {
            $query->level($this->levelFilter);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('message', 'like', "%{$this->search}%")
                    ->orWhere('file_path', 'like', "%{$this->search}%")
                    ->orWhereHas('site', fn ($sq) => $sq->where('name', 'like', "%{$this->search}%"));
            });
        }

        return $query->paginate(30);
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'fatal' => ErrorLog::unresolved()->whereHas('site')->where('level', 'fatal')->count(),
            'error' => ErrorLog::unresolved()->whereHas('site')->where('level', 'error')->count(),
            'warning' => ErrorLog::unresolved()->whereHas('site')->where('level', 'warning')->count(),
        ];
    }

    #[Computed]
    public function sites()
    {
        return Site::orderBy('name')->get(['id', 'name']);
    }

    public function toggleExpand(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function resolveError(int $id): void
    {
        $errorLog = ErrorLog::findOrFail($id);
        ErrorLogService::resolve($errorLog, auth()->user());
        unset($this->errors, $this->stats);
    }

    public function resolveAll(): void
    {
        $query = ErrorLog::unresolved()->whereHas('site');

        if ($this->siteFilter !== 'all') {
            $query->where('site_id', $this->siteFilter);
        }
        if ($this->levelFilter !== 'all') {
            $query->where('level', $this->levelFilter);
        }

        $query->update([
            'is_resolved' => true,
            'resolved_by' => auth()->id(),
            'resolved_at' => now(),
        ]);

        unset($this->errors, $this->stats);
    }

    public function render()
    {
        return view('livewire.dashboard.global-errors')
            ->layout('components.layouts.app', ['title' => 'Errors']);
    }
}
