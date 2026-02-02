<?php

namespace App\Livewire\Reports;

use Livewire\Component;

class ReportsOverview extends Component
{
    public function render()
    {
        return view('livewire.reports.reports-overview')
            ->layout('components.layouts.app', ['title' => 'Reports']);
    }
}
