<?php

namespace App\Jobs;

use App\Models\AnalyticsCache;
use App\Models\Site;
use App\Services\GoogleAnalyticsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchAnalyticsData implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public Site $site,
        public string $dateRange = '28d'
    ) {}

    public function handle(): void
    {
        $connection = $this->site->analyticsConnection;
        if (!$connection || !$connection->is_active) return;

        $google = $connection->googleConnection;
        if (!$google || !$google->is_active) return;

        $service = new GoogleAnalyticsService($google);
        $propertyId = $connection->property_id;

        [$startDate, $endDate] = $this->getDateRange();

        try {
            $data = [
                'overview' => $service->getOverview($propertyId, $startDate, $endDate),
                'users_over_time' => $service->getUsersOverTime($propertyId, $startDate, $endDate),
                'traffic_sources' => $service->getTrafficSources($propertyId, $startDate, $endDate),
                'top_pages' => $service->getTopPages($propertyId, $startDate, $endDate),
                'devices' => $service->getDevices($propertyId, $startDate, $endDate),
                'countries' => $service->getCountries($propertyId, $startDate, $endDate),
                'cities' => $service->getCities($propertyId, $startDate, $endDate),
            ];

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

        } catch (\Exception $e) {
            $connection->update(['last_error' => $e->getMessage()]);
            throw $e;
        }
    }

    private function getDateRange(): array
    {
        $endDate = now()->subDay()->format('Y-m-d');

        $startDate = match ($this->dateRange) {
            '7d' => now()->subDays(7)->format('Y-m-d'),
            '28d' => now()->subDays(28)->format('Y-m-d'),
            '90d' => now()->subDays(90)->format('Y-m-d'),
            default => now()->subDays(28)->format('Y-m-d'),
        };

        return [$startDate, $endDate];
    }
}
