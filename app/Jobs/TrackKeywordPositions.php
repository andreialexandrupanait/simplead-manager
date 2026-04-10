<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KeywordPosition;
use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\GoogleSearchConsoleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class TrackKeywordPositions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public array $backoff = [30, 60];

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'keyword-tracking-'.$this->site->id;
    }

    public function handle(): void
    {
        $connection = $this->site->searchConsoleConnection;

        if (! $connection || ! $connection->is_active) {
            return;
        }

        $google = $connection->googleConnection;

        if (! $google || ! $google->is_active) {
            return;
        }

        $keywords = $this->site->trackedKeywords()->get();

        if ($keywords->isEmpty()) {
            return;
        }

        // GSC data has a ~3 day processing lag
        $endDate = now()->subDays(3)->format('Y-m-d');
        $startDate = now()->subDays(10)->format('Y-m-d');

        $propertyUrl = $connection->property_url;
        $service = new GoogleSearchConsoleService($google);

        $updatedCount = 0;

        foreach ($keywords as $trackedKeyword) {
            $rows = $service->getQueryPositionHistory(
                $propertyUrl,
                $startDate,
                $endDate,
                $trackedKeyword->keyword,
            );

            foreach ($rows as $row) {
                if (empty($row['date'])) {
                    continue;
                }

                KeywordPosition::updateOrCreate(
                    [
                        'tracked_keyword_id' => $trackedKeyword->id,
                        'date' => $row['date'],
                    ],
                    [
                        'position' => $row['position'],
                        'clicks' => $row['clicks'],
                        'impressions' => $row['impressions'],
                        'ctr' => $row['ctr'],
                    ],
                );
            }

            $updatedCount++;
        }

        ActivityLogger::log(
            type: 'seo',
            severity: 'info',
            title: "Keyword positions updated for {$this->site->name}",
            description: "Tracked {$updatedCount} keyword(s) via Search Console",
            site: $this->site,
            metadata: ['keyword_count' => $updatedCount, 'property' => $propertyUrl],
            icon: 'magnifying-glass',
        );
    }

    public function failed(?\Throwable $exception): void
    {
        ActivityLogger::log(
            type: 'seo',
            severity: 'warning',
            title: "Keyword tracking failed for {$this->site->name}",
            description: $exception?->getMessage() ?? 'Unknown error',
            site: $this->site,
            metadata: ['error' => $exception?->getMessage()],
            icon: 'magnifying-glass',
        );
    }
}
