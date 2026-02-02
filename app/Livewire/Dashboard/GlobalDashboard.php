<?php

namespace App\Livewire\Dashboard;

use Livewire\Component;

class GlobalDashboard extends Component
{
    public function render()
    {
        return view('livewire.dashboard.global-dashboard')
            ->layout('components.layouts.app', ['title' => 'Dashboard']);
    }
}
