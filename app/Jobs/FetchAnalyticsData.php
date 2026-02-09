<?php

namespace App\Jobs;

use App\Models\AnalyticsCache;
use App\Models\Site;
use App\Services\GoogleAnalyticsService;
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
        $connection = $this->site->analyticsConnection;
        if (!$connection || !$connection->is_active) return;

        $google = $connection->googleConnection;
        if (!$google || !$google->is_active) return;

        $service = new GoogleAnalyticsService($google);
        $propertyId = $connection->property_id;

        [$startDate, $endDate] = $this->getDateRange();

        try {
            // Compute previous period dates for comparison
            [$prevStart, $prevEnd] = $this->getPreviousPeriodRange($startDate, $endDate);

            $data = [
                'overview' => $service->getOverview($propertyId, $startDate, $endDate),
                'overview_previous' => $service->getOverview($propertyId, $prevStart, $prevEnd),
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

        } catch (\Exception $e) {
            $connection->update(['last_error' => $e->getMessage()]);
            throw $e;
        }
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

    private function getPreviousPeriodRange(string $startDate, string $endDate): array
    {
        $start = \Carbon\Carbon::parse($startDate);
        $end = \Carbon\Carbon::parse($endDate);
        $days = $start->diffInDays($end);

        $prevEnd = $start->copy()->subDay()->format('Y-m-d');
        $prevStart = $start->copy()->subDays($days + 1)->format('Y-m-d');

        return [$prevStart, $prevEnd];
    }
}
