<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KeywordPosition;
use App\Models\Site;
use App\Models\TrackedKeyword;
use App\Services\GoogleSearchConsoleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\RequestException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchKeywordPositions implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public Site $site,
    ) {}

    public function uniqueId(): string
    {
        return 'keyword-positions-'.$this->site->id;
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

        $keywords = TrackedKeyword::where('site_id', $this->site->id)->get();
        if ($keywords->isEmpty()) {
            return;
        }

        $service = new GoogleSearchConsoleService($google);
        $siteUrl = $connection->property_url;
        $date = now()->subDays(3)->format('Y-m-d');

        foreach ($keywords as $keyword) {
            try {
                $results = $service->getFilteredResults(
                    $siteUrl,
                    $date,
                    $date,
                    'query',
                    $keyword->keyword,
                    'query',
                    1
                );

                $row = $results[0] ?? null;

                KeywordPosition::updateOrCreate(
                    [
                        'tracked_keyword_id' => $keyword->id,
                        'date' => $date,
                    ],
                    [
                        'position' => $row ? $row['position'] : null,
                        'clicks' => $row ? $row['clicks'] : 0,
                        'impressions' => $row ? $row['impressions'] : 0,
                        'ctr' => $row ? $row['ctr'] : 0,
                    ]
                );
            } catch (RequestException|\RuntimeException $e) {
                \Illuminate\Support\Facades\Log::warning("Failed to fetch position for keyword '{$keyword->keyword}': {$e->getMessage()}");
            }
        }
    }
}
