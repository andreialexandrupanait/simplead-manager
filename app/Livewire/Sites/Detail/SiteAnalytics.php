<?php

namespace App\Livewire\Sites\Detail;

use App\Models\Site;
use Livewire\Component;

class SiteAnalytics extends Component
{
    public Site $site;

    public function mount(Site $site): void
    {
        $this->site = $site;
    }

    public function render()
    {
        return view('livewire.sites.detail.site-analytics')
            ->layout('components.layouts.app', [
                'siteContext' => $this->site,
                'title' => $this->site->name . ' — Analytics',
            ]);
    }
}
