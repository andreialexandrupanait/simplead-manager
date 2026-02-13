<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\SyncErrorLogsJob;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\ErrorLog;
use App\Models\Site;
use App\Services\ErrorLogService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class SiteErrorLogs extends Component
{
    use WithPagination, WithSiteAuthorization;

    public Site $site;

    #[Url]
    public string $levelFilter = 'all';

    #[Url]
    public bool $showResolved = false;

    public ?int $expandedId = null;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
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
        $counts = $this->site->errorLogs()->unresolved()
            ->selectRaw("
                SUM(CASE WHEN level = 'fatal' THEN 1 ELSE 0 END) as fatal,
                SUM(CASE WHEN level = 'error' THEN 1 ELSE 0 END) as error,
                SUM(CASE WHEN level = 'warning' THEN 1 ELSE 0 END) as warning
            ")
            ->first();

        return [
            'fatal' => (int) $counts->fatal,
            'error' => (int) $counts->error,
            'warning' => (int) $counts->warning,
        ];
    }

    public function toggleExpand(int $id): void
    {
        $this->expandedId = $this->expandedId === $id ? null : $id;
    }

    public function resolveError(int $id): void
    {
        $errorLog = $this->site->errorLogs()->findOrFail($id);
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
