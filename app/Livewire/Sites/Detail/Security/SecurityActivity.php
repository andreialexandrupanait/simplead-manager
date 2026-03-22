<?php

namespace App\Livewire\Sites\Detail\Security;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Livewire\Traits\WithTableFilters;
use App\Models\SecurityActivityLog;
use App\Models\Site;
use App\Services\SecurityActivityService;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class SecurityActivity extends Component
{
    use WithPagination, WithSiteAuthorization, WithTableFilters;

    public Site $site;

    public string $filterEventType = '';

    public string $filterIp = '';

    public string $filterUsername = '';

    public int $filterDays = 7;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function failedLoginStats(): array
    {
        return app(SecurityActivityService::class)->getFailedLoginStats($this->site, $this->filterDays);
    }

    #[Computed]
    public function eventTypes(): array
    {
        return SecurityActivityLog::where('site_id', $this->site->id)
            ->where('event_category', '!=', 'backup')
            ->distinct()
            ->pluck('event_type')
            ->sort()
            ->values()
            ->toArray();
    }

    public function updatedFilterEventType(): void
    {
        $this->resetPage();
    }

    public function updatedFilterIp(): void
    {
        $this->resetPage();
    }

    public function updatedFilterUsername(): void
    {
        $this->resetPage();
    }

    public function updatedFilterDays(): void
    {
        $this->filterDays = max(1, min(365, $this->filterDays));
        $this->resetPage();
        unset($this->failedLoginStats);
    }

    public function render()
    {
        $query = SecurityActivityLog::where('site_id', $this->site->id)
            ->where('event_category', '!=', 'backup')
            ->where('occurred_at', '>=', now()->subDays($this->filterDays))
            ->orderByDesc('occurred_at');

        if ($this->filterEventType) {
            $query->where('event_type', $this->filterEventType);
        }
        if ($this->filterIp) {
            $query->where('ip_address', $this->filterIp);
        }
        if ($this->filterUsername) {
            $query->where('username', 'ilike', "%{$this->filterUsername}%");
        }

        return view('livewire.sites.detail.security.security-activity', [
            'logs' => $query->paginate(25),
        ])->layout('components.layouts.app', [
            'siteContext' => $this->site,
            'title' => $this->site->name.' — Activity',
        ]);
    }
}
