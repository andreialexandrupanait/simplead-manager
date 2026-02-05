<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\SyncErrorLogsJob;
use App\Models\ErrorLog;
use App\Models\Site;
use App\Services\ErrorLogService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class SiteErrorLogs extends Component
{
    use WithPagination;

    public Site $site;

    #[Url]
    public string $levelFilter = 'all';

    #[Url]
    public bool $showResolved = false;

    public ?int $expandedId = null;

    public function mount(Site $site): void
    {
        $this->site = $site;
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
        $query = $this->site->errorLogs()
            ->orderByDesc('last_seen_at');

        if (!$this->showResolved) {
            $query->unresolved();
        }

        if ($this->levelFilter !== 'all') {
            $query->level($this->levelFilter);
        }

        return $query->paginate(30);
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'fatal' => $this->site->errorLogs()->unresolved()->where('level', 'fatal')->count(),
            'error' => $this->site->errorLogs()->unresolved()->where('level', 'error')->count(),
            'warning' => $this->site->errorLogs()->unresolved()->where('level', 'warning')->count(),
        ];
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
        ErrorLogService::resolveAll($this->site, auth()->user());
        unset($this->errors, $this->stats);
    }

    public function syncNow(): void
    {
        SyncErrorLogsJob::dispatch($this->site);
        session()->flash('error-log-success', 'Error log sync has been queued.');
        unset($this->errors, $this->stats);
    }

    public function render()
    {
        return view('livewire.sites.detail.site-error-logs')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Errors',
            ]);
    }
}
