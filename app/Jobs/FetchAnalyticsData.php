<?php

namespace App\Jobs;

use App\Models\AnalyticsCache;
use App\Models\Site;
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
    ) {}

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
            $data = [
                'overview' => $service->getOverview($propertyId, $startDate, $endDate),
                'users_over_time' => $service->getUsersOverTime($propertyId, $startDate, $endDate),
                'traffic_sources' => $service->getTrafficSources($propertyId, $startDate, $endDate),
                'top_pages' => $service->getTopPages($propertyId, $startDate, $endDate),
                'devices' => $service->getDevices($propertyId, $startDate, $endDate),
                'countries' => $service->getCountries($propertyId, $startDate, $endDate),
                'cities' => $service->getCities($propertyId, $startDate, $endDate),
                'referral_sources' => $service->getReferralSources($propertyId, $startDate, $endDate),
                'landing_pages' => $service->getLandingPages($propertyId, $startDate, $endDate),
                'demographics' => $service->getDemographics($propertyId, $startDate, $endDate),
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

            JobTracker::complete($this->uniqueId(), 'Analytics data fetched');

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
