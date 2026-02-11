<?php

namespace App\Jobs;

use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Services\GoogleSearchConsoleService;
use App\Services\JobTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchSearchConsoleData implements ShouldQueue, ShouldBeUnique
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
    ) {}

    public function uniqueId(): string
    {
        return 'search-console-' . $this->site->id;
    }

    public function handle(): void
    {
        JobTracker::start($this->uniqueId(), 'Fetching Search Console data...');

        $connection = $this->site->searchConsoleConnection;
        if (!$connection || !$connection->is_active) return;

        $google = $connection->googleConnection;
        if (!$google || !$google->is_active) return;

        $siteUrl = $connection->property_url;
        [$startDate, $endDate] = $this->getDateRange();

        try {
            $service = new GoogleSearchConsoleService($google);

            $dataTypes = [
                'overview' => fn () => $service->getOverview($siteUrl, $startDate, $endDate),
                'performance_over_time' => fn () => $service->getPerformanceOverTime($siteUrl, $startDate, $endDate),
                'queries' => fn () => $service->getTopQueries($siteUrl, $startDate, $endDate, 50),
                'pages' => fn () => $service->getTopPages($siteUrl, $startDate, $endDate, 50),
                'countries' => fn () => $service->getCountries($siteUrl, $startDate, $endDate, 25),
                'devices' => fn () => $service->getDevices($siteUrl, $startDate, $endDate),
                'search_appearance' => fn () => $service->getSearchAppearance($siteUrl, $startDate, $endDate),
                'sitemaps' => fn () => $service->getSitemaps($siteUrl),
            ];

            foreach ($dataTypes as $type => $fetcher) {
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
            }

            $connection->update([
                'last_sync_at' => now(),
                'last_error' => null,
            ]);

            JobTracker::complete($this->uniqueId(), 'Search Console data fetched');

        } catch (\Exception $e) {
            $connection->update(['last_error' => $e->getMessage()]);
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        JobTracker::fail($this->uniqueId(), 'Fetch failed: ' . ($exception?->getMessage() ?? 'Unknown error'));
    }

    private function getDateRange(): array
    {
        if ($this->dateRange === 'custom' && $this->customStart && $this->customEnd) {
            return [$this->customStart, $this->customEnd];
        }

        $endDate = now()->subDays(3)->format('Y-m-d');

        $startDate = match ($this->dateRange) {
            '7d' => now()->subDays(10)->format('Y-m-d'),
            '28d' => now()->subDays(31)->format('Y-m-d'),
            '90d' => now()->subDays(93)->format('Y-m-d'),
            default => now()->subDays(31)->format('Y-m-d'),
        };

        return [$startDate, $endDate];
    }

}
