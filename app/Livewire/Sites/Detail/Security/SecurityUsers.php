<?php

namespace App\Livewire\Sites\Detail\Security;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Livewire\Traits\WithSorting;
use App\Models\Site;
use App\Models\SiteUser;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

class SecurityUsers extends Component
{
    use WithPagination, WithSorting, WithSiteAuthorization;

    public Site $site;
    public string $roleFilter = '';

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
        if ($this->sortBy === 'name') {
            $this->sortBy = 'role';
        }
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function lastSynced()
    {
        return SiteUser::where('site_id', $this->site->id)->max('synced_at');
    }

    #[Computed]
    public function roleCounts()
    {
        return SiteUser::where('site_id', $this->site->id)
            ->selectRaw('role, COUNT(*) as count')
            ->groupBy('role')
            ->pluck('count', 'role')
            ->toArray();
    }

    public function render()
    {
        $query = SiteUser::where('site_id', $this->site->id);

        if ($this->roleFilter) {
            $query->where('role', $this->roleFilter);
        }

        $query->orderBy($this->sortBy, $this->sortDir)
            ->orderBy('username');

        return view('livewire.sites.detail.security.security-users', [
            'users' => $query->paginate(50),
        ])->layout('components.layouts.app', [
            'siteContext' => $this->site,
            'title' => $this->site->name . ' — Users',
        ]);
    }
}
