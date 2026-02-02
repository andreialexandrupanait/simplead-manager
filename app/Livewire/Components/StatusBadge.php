<?php

namespace App\Livewire\Components;

use Livewire\Component;

class StatusBadge extends Component
{
    public string $status;

    public function getVariantProperty(): string
    {
        return match ($this->status) {
            'online' => 'green',
            'offline' => 'red',
            'warning' => 'yellow',
            'maintenance' => 'purple',
            default => 'gray',
        };
    }

    public function getLabelProperty(): string
    {
        return match ($this->status) {
            'online' => 'Online',
            'offline' => 'Offline',
            'warning' => 'Warning',
            'maintenance' => 'Maintenance',
            default => 'Unknown',
        };
    }

    public function render()
    {
        return view('livewire.components.status-badge');
    }
}
