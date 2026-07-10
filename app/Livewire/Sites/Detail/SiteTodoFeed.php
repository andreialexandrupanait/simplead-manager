<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use App\Services\SiteTodoService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SiteTodoFeed extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    #[Computed]
    public function todos(): array
    {
        return SiteTodoService::forSite($this->site);
    }

    public function render()
    {
        return view('livewire.sites.detail.site-todo-feed');
    }
}
