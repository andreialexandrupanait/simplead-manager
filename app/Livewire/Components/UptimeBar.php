<?php

namespace App\Livewire\Components;

use App\Models\UptimeMonitor;
use Livewire\Component;

class UptimeBar extends Component
{
    public UptimeMonitor $monitor;

    public function getSegmentsProperty(): array
    {
        $segments = array_fill(0, 96, null); // 96 segments = 15-min slices over 24h

        $checks = $this->monitor->checks()
            ->where('checked_at', '>=', now()->subHours(24))
            ->orderBy('checked_at')
            ->get(['is_up', 'checked_at']);

        $startOfPeriod = now()->subHours(24);

        foreach ($checks as $check) {
            $minutesSinceStart = $startOfPeriod->diffInMinutes($check->checked_at);
            $index = min(95, intdiv((int) $minutesSinceStart, 15));

            // If segment already has data, it's "up" only if all checks in that segment are up
            if ($segments[$index] === null) {
                $segments[$index] = $check->is_up;
            } elseif (! $check->is_up) {
                $segments[$index] = false;
            }
        }

        return $segments;
    }

    public function render()
    {
        return view('livewire.components.uptime-bar');
    }
}
