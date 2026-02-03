<?php

namespace App\Jobs;

use App\Models\SearchConsoleCache;
use App\Models\Site;
use App\Services\GoogleSearchConsoleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchSearchConsoleData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Site $site,
        public string $dateRange = '28d'
    ) {}

    public function handle(): void
    {
        $connection = $this->site->searchConsoleConnection;
        if (!$connection || !$connection->is_active) return;

        $google = $connection->googleConnection;
        if (!$google || !$google->is_active) return;

        $service = new GoogleSearchConsoleService($google);
        $siteUrl = $connection->property_url;

        [$startDate, $endDate] = $this->getDateRange();

        try {
            $dataTypes = [
                'overview' => fn () => $service->getOverview($siteUrl, $startDate, $endDate),
                'performance_over_time' => fn () => $service->getPerformanceOverTime($siteUrl, $startDate, $endDate),
                'queries' => fn () => $service->getTopQueries($siteUrl, $startDate, $endDate),
                'pages' => fn () => $service->getTopPages($siteUrl, $startDate, $endDate),
                'countries' => fn () => $service->getCountries($siteUrl, $startDate, $endDate),
                'devices' => fn () => $service->getDevices($siteUrl, $startDate, $endDate),
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

        } catch (\Exception $e) {
            $connection->update(['last_error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function getDateRange(): array
    {
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
