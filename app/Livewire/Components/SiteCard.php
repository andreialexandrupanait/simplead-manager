<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use App\Enums\HealthLevel;
use App\Models\Site;
use Livewire\Component;

class SiteCard extends Component
{
    public Site $site;

    public function render()
    {
        $healthLevel = HealthLevel::fromScore($this->site->health_score, $this->site->is_up);

        return view('livewire.components.site-card', [
            'healthLevel' => $healthLevel,
        ]);
    }
}
