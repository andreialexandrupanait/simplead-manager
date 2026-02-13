<?php

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

class FetchAnalyticsData implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;
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
        return 'analytics-' . $this->site->id;
    }

    public function handle(): void
    {
        JobTracker::start($this->uniqueId(), 'Fetching Analytics data...');

        $connection = $this->site->analyticsConnection;
        if (!$connection || !$connection->is_active) {
            JobTracker::complete($this->uniqueId(), 'Skipped — no active connection');
            return;
        }

        $google = $connection->googleConnection;
        if (!$google || !$google->is_active) {
            JobTracker::complete($this->uniqueId(), 'Skipped — no active Google connection');
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

            CircuitBreakerService::recordSuccess($this->site);
            JobTracker::complete($this->uniqueId(), 'Analytics data fetched');

        } catch (\Exception $e) {
            $connection->update(['last_error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        CircuitBreakerService::recordFailure($this->site, $exception?->getMessage() ?? 'Analytics fetch failed');
        JobTracker::fail($this->uniqueId(), 'Fetch failed: ' . ($exception?->getMessage() ?? 'Unknown error'));
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

}
