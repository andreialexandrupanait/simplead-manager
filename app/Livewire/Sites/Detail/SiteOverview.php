<?php

namespace App\Livewire\Sites\Detail;

use App\Models\Site;
use Livewire\Component;

class SiteOverview extends Component
{
    public Site $site;

    public function mount(Site $site): void
    {
        $this->site = $site;
    }

    public function render()
    {
        return view('livewire.sites.detail.site-overview')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Overview',
            ]);
    }
}
