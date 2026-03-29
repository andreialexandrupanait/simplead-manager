<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use App\Models\UptimeCheck;
use App\Models\UptimeMonitor;
use Livewire\Component;

class ResponseTimeChart extends Component
{
    public UptimeMonitor $monitor;

    public string $range = '24h';

    public function setRange(string $range): void
    {
        $this->range = $range;
    }

    public function getChartDataProperty(): array
    {
        $since = match ($this->range) {
            '1h' => now()->subHour(),
            '24h' => now()->subHours(24),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subHours(24),
        };

        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\UptimeCheck> $checks */
        $checks = $this->monitor->checks()
            ->where('checked_at', '>=', $since)
            ->whereNotNull('response_time')
            ->orderBy('checked_at')
            ->get(['response_time', 'checked_at']);

        $format = match ($this->range) {
            '1h' => 'H:i',
            '24h' => 'H:i',
            '7d' => 'M d H:i',
            '30d' => 'M d',
            default => 'H:i',
        };

        $labels = $checks->map(fn (UptimeCheck $c) => $c->checked_at->format($format))->toArray();
        $data = $checks->pluck('response_time')->toArray();

        return [
            'labels' => $labels,
            'data' => $data,
        ];
    }

    public function getResponseStatsProperty(): array
    {
        $since = match ($this->range) {
            '1h' => now()->subHour(),
            '24h' => now()->subHours(24),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subHours(24),
        };

        $query = $this->monitor->checks()
            ->where('checked_at', '>=', $since)
            ->where('is_up', true)
            ->whereNotNull('response_time');

        /** @var \stdClass $stats */
        $stats = (clone $query)->selectRaw('AVG(response_time) as avg_rt, MIN(response_time) as min_rt, MAX(response_time) as max_rt, COUNT(*) as total')->first();

        $avg = (int) round((float) ($stats->avg_rt ?? 0));
        $min = (int) ($stats->min_rt ?? 0);
        $max = (int) ($stats->max_rt ?? 0);

        $p95 = 0;
        $total = (int) $stats->total;
        if ($total > 0) {
            $offset = (int) floor($total * 0.95) - 1;
            $p95 = (int) (clone $query)
                ->orderBy('response_time')
                ->offset(max(0, $offset))
                ->limit(1)
                ->value('response_time');
        }

        return compact('avg', 'min', 'max', 'p95');
    }

    public function render()
    {
        return view('livewire.components.response-time-chart');
    }
}
