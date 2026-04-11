<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KeywordPosition;
use App\Models\Site;
use App\Services\ActivityLogger;
use App\Services\GoogleSearchConsoleService;
use App\Services\KeywordTrackingService;
use App\Services\Notifications\NotificationService;
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

        // Check for significant position changes and notify
        $significantChanges = [];
        foreach ($keywords as $trackedKeyword) {
            $positions = $trackedKeyword->positions()->orderByDesc('date')->limit(2)->pluck('position', 'date')->all();
            $values = array_values($positions);
            if (count($values) >= 2) {
                $current = $values[0];
                $previous = $values[1];
                $diff = $previous - $current; // positive = improved

                if (abs($diff) >= 5) {
                    $significantChanges[] = [
                        'keyword' => $trackedKeyword->keyword,
                        'from' => $previous,
                        'to' => $current,
                        'diff' => $diff,
                    ];
                }

                // Notify top 3 / top 10 milestones
                if ($current <= 3 && $previous > 3) {
                    NotificationService::notifySiteEvent(
                        site: $this->site,
                        event: 'keyword_top3',
                        title: 'Keyword entered Top 3',
                        message: "\"{$trackedKeyword->keyword}\" reached position {$current} (was {$previous}).",
                        severity: 'info',
                    );
                } elseif ($current <= 10 && $previous > 10) {
                    NotificationService::notifySiteEvent(
                        site: $this->site,
                        event: 'keyword_top10',
                        title: 'Keyword entered Top 10',
                        message: "\"{$trackedKeyword->keyword}\" reached position {$current} (was {$previous}).",
                        severity: 'info',
                    );
                }
            }
        }

        // Sync keyword-to-page mappings from GSC
        $mappingsSynced = app(KeywordTrackingService::class)->syncKeywordPageMappings($this->site);

        // Track published content performance — match target keywords to positions
        $publishedContent = \App\Models\SeoContent::where('site_id', $this->site->id)
            ->where('status', 'published')
            ->whereNotNull('target_keyword')
            ->get();

        foreach ($publishedContent as $content) {
            $kw = mb_strtolower(trim($content->target_keyword));
            $tracked = $keywords->first(fn ($tk) => mb_strtolower($tk->keyword) === $kw);

            if ($tracked) {
                $latestPosition = $tracked->positions()->orderByDesc('date')->first();
                if ($latestPosition && $latestPosition->position !== null) {
                    $content->update([
                        'ranking_position' => $latestPosition->position,
                        'ranking_date' => $latestPosition->date,
                    ]);
                }
            }
        }

        ActivityLogger::log(
            type: 'seo',
            severity: 'info',
            title: "Keyword positions updated for {$this->site->name}",
            description: "Tracked {$updatedCount} keyword(s) via Search Console, synced {$mappingsSynced} page mapping(s)",
            site: $this->site,
            metadata: ['keyword_count' => $updatedCount, 'property' => $propertyUrl, 'significant_changes' => count($significantChanges), 'page_mappings' => $mappingsSynced],
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
