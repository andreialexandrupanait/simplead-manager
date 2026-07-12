<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\AnalyticsCache;
use App\Models\Site;
use App\Services\CircuitBreakerService;
use App\Services\GoogleAnalyticsService;
use App\Services\JobTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchAnalyticsData implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 360; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public array $backoff = [30, 60];

    public function __construct(
        public Site $site,
        public string $dateRange = '28d',
        public ?string $customStart = null,
        public ?string $customEnd = null,
    ) {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        // Include the date range so a user-requested 7d/90d/custom fetch is not
        // dedup-dropped by an in-flight scheduled 28d job (and vice versa) — the
        // UI spinner would otherwise never resolve (P1-49).
        return 'analytics-'.$this->site->id.'-'.$this->rangeKey();
    }

    private function rangeKey(): string
    {
        if ($this->dateRange === 'custom') {
            return 'custom-'.($this->customStart ?? '').'-'.($this->customEnd ?? '');
        }

        return $this->dateRange;
    }

    public function handle(): void
    {
        JobTracker::start($this->uniqueId(), 'Fetching Analytics data...');

        $connection = $this->site->analyticsConnection;
        if (! $connection || ! $connection->is_active) {
            JobTracker::complete($this->uniqueId(), 'Skipped â no active connection');

            return;
        }

        $google = $connection->googleConnection;
        if (! $google || ! $google->is_active) {
            JobTracker::complete($this->uniqueId(), 'Skipped â no active Google connection');

            return;
        }

        $service = new GoogleAnalyticsService($google);
        $propertyId = $connection->property_id;

        [$startDate, $endDate] = $this->getDateRange();

        try {
            $endpoints = [
                'overview' => fn () => $service->getOverview($propertyId, $startDate, $endDate),
                'users_over_time' => fn () => $service->getUsersOverTime($propertyId, $startDate, $endDate),
                'traffic_sources' => fn () => $service->getTrafficSources($propertyId, $startDate, $endDate),
                'top_pages' => fn () => $service->getTopPages($propertyId, $startDate, $endDate),
            ];

            $data = [];
            $completed = 0;
            $total = count($endpoints);

            foreach ($endpoints as $name => $fetcher) {
                $label = str_replace('_', ' ', $name);
                JobTracker::progress($this->uniqueId(), (int) round($completed / $total * 90), "Fetching {$label}...");
                $data[$name] = $fetcher();
                $completed++;
            }

            // P2-48: fetch the immediately-preceding window of equal length so
            // the "previous period" comparison delta actually renders. This was
            // never requested before, so overview_previous was always missing
            // and every comparison resolved to null (a dead feature).
            [$prevStart, $prevEnd] = $this->getPreviousDateRange($startDate, $endDate);
            $data['overview_previous'] = $service->getOverview($propertyId, $prevStart, $prevEnd);

            AnalyticsCache::updateOrCreate(
                [
                    'site_id' => $this->site->id,
                    'date_range' => $this->dateRange,
                ],
                [
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'data' => $data,
                    'fetched_at' => now(),
                    'expires_at' => now()->addHours(6),
                ]
            );

            $connection->update([
                'last_sync_at' => now(),
                'last_error' => null,
            ]);

            JobTracker::progress($this->uniqueId(), 95, 'Saving data...');

            CircuitBreakerService::recordSuccess($this->site, CircuitBreakerService::DOMAIN_ANALYTICS);
            JobTracker::complete($this->uniqueId(), 'Analytics data fetched');

        } catch (\Exception $e) {
            $connection->update(['last_error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        CircuitBreakerService::recordFailure($this->site, $exception?->getMessage() ?? 'Analytics fetch failed', CircuitBreakerService::DOMAIN_ANALYTICS);
        JobTracker::fail($this->uniqueId(), 'Fetch failed: '.($exception?->getMessage() ?? 'Unknown error'));
    }

    private function getDateRange(): array
    {
        if ($this->dateRange === 'custom' && $this->customStart && $this->customEnd) {
            return [$this->customStart, $this->customEnd];
        }

        // Analytics data has ~1 day processing delay
        $dataDelay = 1;
        $endDate = now()->subDays($dataDelay)->format('Y-m-d');

        $days = match ($this->dateRange) {
            '7d' => 7,
            '28d' => 28,
            '90d' => 90,
            default => 28,
        };

        $startDate = now()->subDays($days + $dataDelay)->format('Y-m-d');

        return [$startDate, $endDate];
    }

    /**
     * The equal-length window immediately preceding [$startDate, $endDate],
     * used for the "previous period" comparison (P2-48).
     *
     * @return array{0: string, 1: string}
     */
    private function getPreviousDateRange(string $startDate, string $endDate): array
    {
        $start = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);

        $lengthDays = $start->diffInDays($end);

        $prevEnd = $start->copy()->subDay();
        $prevStart = $prevEnd->copy()->subDays($lengthDays);

        return [$prevStart->format('Y-m-d'), $prevEnd->format('Y-m-d')];
    }
}
