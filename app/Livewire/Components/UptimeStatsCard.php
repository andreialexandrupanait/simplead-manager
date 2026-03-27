<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use App\Models\UptimeIncident;
use App\Models\UptimeMonitor;
use Livewire\Component;

class UptimeStatsCard extends Component
{
    public string $label;

    public UptimeMonitor $monitor;

    public string $period = '24h';

    public function getStatsProperty(): array
    {
        $since = match ($this->period) {
            '24h' => now()->subHours(24),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '365d' => now()->subDays(365),
            default => now()->subHours(24),
        };

        $uptime = $this->monitor->{"uptime_{$this->period}"};

        $incidents = $this->monitor->incidents()
            ->where('started_at', '>=', $since)
            ->get();

        $incidentCount = $incidents->count();

        $totalDowntimeMinutes = $incidents->sum(function (UptimeIncident $incident) {
            $end = $incident->resolved_at ?? now();

            return $incident->started_at->diffInMinutes($end);
        });

        // Format downtime
        if ($totalDowntimeMinutes < 60) {
            $downtime = $totalDowntimeMinutes.'m';
        } elseif ($totalDowntimeMinutes < 1440) {
            $hours = intdiv($totalDowntimeMinutes, 60);
            $mins = $totalDowntimeMinutes % 60;
            $downtime = $mins > 0 ? "{$hours}h {$mins}m" : "{$hours}h";
        } else {
            $days = intdiv($totalDowntimeMinutes, 1440);
            $hours = intdiv($totalDowntimeMinutes % 1440, 60);
            $downtime = $hours > 0 ? "{$days}d {$hours}h" : "{$days}d";
        }

        return [
            'uptime' => $uptime !== null ? number_format($uptime, 2).'%' : 'N/A',
            'incidents' => $incidentCount,
            'downtime' => $totalDowntimeMinutes > 0 ? $downtime : 'None',
        ];
    }

    public function render()
    {
        return view('livewire.components.uptime-stats-card');
    }
}
