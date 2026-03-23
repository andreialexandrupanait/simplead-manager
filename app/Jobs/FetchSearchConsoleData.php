<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Services\CircuitBreakerService;
use App\Services\GoogleSearchConsoleService;
use App\Services\JobTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchSearchConsoleData implements ShouldBeUnique, ShouldQueue
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
        return 'search-console-'.$this->site->id;
    }

    public function handle(): void
    {
        JobTracker::start($this->uniqueId(), 'Fetching Search Console data...');

        $connection = $this->site->searchConsoleConnection;
        if (! $connection || ! $connection->is_active) {
            JobTracker::complete($this->uniqueId(), 'Skipped: no active Search Console connection');

            return;
        }

        $google = $connection->googleConnection;
        if (! $google || ! $google->is_active) {
            JobTracker::complete($this->uniqueId(), 'Skipped: no active Google connection');

            return;
        }

        $siteUrl = $connection->property_url;
        [$startDate, $endDate] = $this->getDateRange();

        try {
            $service = new GoogleSearchConsoleService($google);

            $dataTypes = [
                'overview' => fn () => $service->getOverview($siteUrl, $startDate, $endDate),
                'performance_over_time' => fn () => $service->getPerformanceOverTime($siteUrl, $startDate, $endDate),
                'queries' => fn () => $service->getTopQueries($siteUrl, $startDate, $endDate, 50),
                'pages' => fn () => $service->getTopPages($siteUrl, $startDate, $endDate, 50),
            ];

            $completed = 0;
            $total = count($dataTypes);

            foreach ($dataTypes as $type => $fetcher) {
                $label = str_replace('_', ' ', $type);
                JobTracker::progress($this->uniqueId(), (int) round($completed / $total * 90), "Fetching {$label}...");

                $data = $fetcher();

                SearchConsoleCache::updateOrCreate(
                    [
                        'site_id' => $this->site->id,
                        'date_range' => $this->dateRange,
                        'data_type' => $type,
                    ],
                    [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'data' => $data,
                        'fetched_at' => now(),
                        'expires_at' => now()->addHours(6),
                    ]
                );

                $completed++;
            }

            $connection->update([
                'last_sync_at' => now(),
                'last_error' => null,
            ]);

            JobTracker::progress($this->uniqueId(), 95, 'Saving data...');

            CircuitBreakerService::recordSuccess($this->site);
            JobTracker::complete($this->uniqueId(), 'Search Console data fetched');

        } catch (\Exception $e) {
            $connection->update(['last_error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        CircuitBreakerService::recordFailure($this->site, $exception?->getMessage() ?? 'Search Console fetch failed');
        JobTracker::fail($this->uniqueId(), 'Fetch failed: '.($exception?->getMessage() ?? 'Unknown error'));
    }

    private function getDateRange(): array
    {
        if ($this->dateRange === 'custom' && $this->customStart && $this->customEnd) {
            return [$this->customStart, $this->customEnd];
        }

        // Search Console data has ~3 day processing delay
        $dataDelay = 3;
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
