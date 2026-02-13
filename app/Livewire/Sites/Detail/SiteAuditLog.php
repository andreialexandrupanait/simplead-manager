<?php

namespace App\Livewire\Sites\Detail;

use App\Jobs\SyncAuditLogs;
use App\Livewire\Traits\WithSiteAuthorization;
use App\Livewire\Traits\WithTableFilters;
use App\Models\Site;
use App\Models\WpAuditLog;
use App\Services\AuditLogService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Url;
use Livewire\Component;

class SiteAuditLog extends Component
{
    use WithTableFilters, WithSiteAuthorization;

    public Site $site;

    #[Url]
    public string $actionFilter = 'all';

    #[Url]
    public string $userFilter = 'all';

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function logs()
    {
        $query = WpAuditLog::where('site_id', $this->site->id)
            ->orderByDesc('action_at');

        if ($this->actionFilter !== 'all') {
            $query->where('action_type', $this->actionFilter);
        }

        if ($this->userFilter !== 'all') {
            $query->where('wp_username', $this->userFilter);
        }

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('object_title', 'like', "%{$this->search}%")
                  ->orWhere('wp_username', 'like', "%{$this->search}%")
                  ->orWhere('ip_address', 'like', "%{$this->search}%");
            });
        }

        return $query->paginate(50);
    }

    #[Computed]
    public function users()
    {
        return WpAuditLog::where('site_id', $this->site->id)
            ->whereNotNull('wp_username')
            ->distinct()
            ->pluck('wp_username')
            ->sort()
            ->values();
    }

    #[Computed]
    public function actionTypes()
    {
        return WpAuditLog::where('site_id', $this->site->id)
            ->distinct()
            ->pluck('action_type')
            ->sort()
            ->values();
    }

    public function syncNow(): void
    {
        SyncAuditLogs::dispatch($this->site);
        session()->flash('sync-dispatched', 'Audit log sync has been dispatched.');
        unset($this->logs, $this->users, $this->actionTypes);
    }

    public function exportCsv()
    {
        $path = AuditLogService::export($this->site, [
            'action_type' => $this->actionFilter,
            'wp_username' => $this->userFilter,
            'search' => $this->search,
        ]);

        return response()->download($path)->deleteFileAfterSend(true);
    }

    public function updatedActionFilter(): void
    {
        $this->resetPage();
        unset($this->logs);
    }

    public function updatedUserFilter(): void
    {
        $this->resetPage();
        unset($this->logs);
    }

    public function render()
    {
        return view('livewire.sites.detail.site-audit-log')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Audit Log',
            ]);
    }
}
