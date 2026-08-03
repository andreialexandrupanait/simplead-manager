<?php

declare(strict_types=1);

namespace App\Livewire\Components;

use App\Models\UptimeCheck;
use App\Models\UptimeHourlyAggregate;
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

    private function since(): \Illuminate\Support\Carbon
    {
        return match ($this->range) {
            '1h' => now()->subHour(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => now()->subHours(24),
        };
    }

    /**
     * 1h/24h plot every raw ping. 7d/30d would be thousands of points (a 5-min
     * interval is ~8 640 pings per month), so they plot avg + p95 per hour/day
     * instead — p95 because an average hides exactly the spikes a client notices.
     */
    public function getChartDataProperty(): array
    {
        if (in_array($this->range, ['7d', '30d'], true)) {
            return $this->bucketedChartData();
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, \App\Models\UptimeCheck> $checks */
        $checks = $this->monitor->checks()
            ->where('checked_at', '>=', $this->since())
            ->whereNotNull('response_time')
            ->orderBy('checked_at')
            ->get(['response_time', 'checked_at']);

        return [
            'labels' => $checks->map(fn (UptimeCheck $c) => $c->checked_at->format('H:i'))->toArray(),
            'datasets' => [
                [
                    'label' => 'Response Time (ms)',
                    'data' => $checks->pluck('response_time')->toArray(),
                    'color' => '#7B68EE',
                ],
            ],
        ];
    }

    /**
     * Buckets come from BOTH sources, raw preferred: raw pings are exact but
     * pruned after ~14 days, while uptime_hourly_aggregates reaches back 13
     * months but only from the day the aggregator was first deployed. Merging
     * gives each bucket the best data that still exists for it.
     */
    private function bucketedChartData(): array
    {
        $unit = $this->range === '7d' ? 'hour' : 'day';
        $since = $this->since();

        $raw = $this->monitor->checks()
            ->where('checked_at', '>=', $since)
            ->whereNotNull('response_time')
            ->selectRaw(
                "date_trunc('{$unit}', checked_at) AS bucket,
                 ROUND(AVG(response_time)) AS avg_rt,
                 ROUND(PERCENTILE_CONT(0.95) WITHIN GROUP (ORDER BY response_time)) AS p95_rt"
            )
            ->groupByRaw('1')
            ->toBase()
            ->get();

        $aggregated = UptimeHourlyAggregate::query()
            ->where('monitor_id', $this->monitor->id)
            ->where('bucket_hour', '>=', $since)
            ->whereNotNull('avg_response_time')
            ->selectRaw(
                "date_trunc('{$unit}', bucket_hour) AS bucket,
                 ROUND(SUM(avg_response_time::numeric * checks_total) / NULLIF(SUM(checks_total), 0)) AS avg_rt,
                 MAX(p95_response_time) AS p95_rt"
            )
            ->groupByRaw('1')
            ->toBase()
            ->get();

        $buckets = [];
        foreach ($aggregated as $row) {
            $buckets[(string) $row->bucket] = $row;
        }
        foreach ($raw as $row) {
            $buckets[(string) $row->bucket] = $row;
        }
        ksort($buckets);

        $format = $this->range === '7d' ? 'M d H:i' : 'M d';
        $labels = [];
        $avg = [];
        $p95 = [];

        foreach ($buckets as $key => $row) {
            $labels[] = \Illuminate\Support\Carbon::parse($key)->format($format);
            $avg[] = $row->avg_rt !== null ? (int) $row->avg_rt : null;
            $p95[] = $row->p95_rt !== null ? (int) $row->p95_rt : null;
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Avg (ms)',
                    'data' => $avg,
                    'color' => '#7B68EE',
                    'pointRadius' => 0,
                ],
                [
                    // Rendered as a soft band from the avg line up to p95 (fill
                    // '-1') — a full-strength dashed line at hourly resolution
                    // zigzags so hard the chart is unreadable.
                    'label' => 'P95 (ms)',
                    'data' => $p95,
                    'color' => '#F59E0B',
                    'pointRadius' => 0,
                    'borderWidth' => 1,
                    'fill' => '-1',
                ],
            ],
        ];
    }

    public function getResponseStatsProperty(): array
    {
        // Raw pings are pruned after ~14 days, so 30d stats must come from the
        // hourly aggregates or they would silently describe a shorter window.
        if ($this->range === '30d') {
            /** @var \stdClass|null $stats */
            $stats = UptimeHourlyAggregate::query()
                ->where('monitor_id', $this->monitor->id)
                ->where('bucket_hour', '>=', $this->since())
                ->whereNotNull('avg_response_time')
                ->selectRaw(
                    'ROUND(SUM(avg_response_time::numeric * checks_total) / NULLIF(SUM(checks_total), 0)) AS avg_rt,
                     MAX(max_response_time) AS max_rt,
                     MAX(p95_response_time) AS p95_rt'
                )
                ->toBase()
                ->first();

            return [
                'avg' => (int) ($stats->avg_rt ?? 0),
                'min' => null, // not stored per hour
                'max' => (int) ($stats->max_rt ?? 0),
                'p95' => (int) ($stats->p95_rt ?? 0),
            ];
        }

        $query = $this->monitor->checks()
            ->where('checked_at', '>=', $this->since())
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
