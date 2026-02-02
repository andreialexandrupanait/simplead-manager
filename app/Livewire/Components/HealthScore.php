<?php

namespace App\Livewire\Components;

use Livewire\Component;

class HealthScore extends Component
{
    public int $score;

    public string $size = 'md';

    public function getColorProperty(): string
    {
        return match (true) {
            $this->score >= 90 => 'green',
            $this->score >= 70 => 'yellow',
            default => 'red',
        };
    }

    public function render()
    {
        return view('livewire.components.health-score');
    }
}
