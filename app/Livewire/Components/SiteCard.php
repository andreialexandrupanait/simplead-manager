<?php

namespace App\Livewire\Components;

use App\Models\Site;
use Livewire\Component;

class SiteCard extends Component
{
    public Site $site;

    public function mount(Site $site): void
    {
        $this->site = $site;
    }

    public function render()
    {
        return view('livewire.components.site-card');
    }
}
