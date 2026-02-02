<?php

namespace App\Livewire\Sites;

use Livewire\Component;

class CreateSite extends Component
{
    public function render()
    {
        return view('livewire.sites.create-site')
            ->layout('components.layouts.app', ['title' => 'Add New Site']);
    }
}
