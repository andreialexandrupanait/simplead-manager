<?php

declare(strict_types=1);

namespace App\Livewire\Sites\Detail\Security;

use App\Livewire\Traits\WithSiteAuthorization;
use App\Models\Site;
use Livewire\Component;

class SecurityComingSoon extends Component
{
    use WithSiteAuthorization;

    public Site $site;

    public function mount(Site $site): void
    {
        $this->authorizeSiteAccess($site);
        $this->site = $site;
    }

    public function render()
    {
        return view('livewire.sites.detail.security.security-coming-soon')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name.' — Coming Soon',
            ]);
    }
}
