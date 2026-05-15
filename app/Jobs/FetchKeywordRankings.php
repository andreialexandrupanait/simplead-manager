<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\SeoKeywordRanking;
use App\Models\Site;
use App\Services\GoogleSearchConsoleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class FetchKeywordRankings implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public Site $site)
    {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'keyword-rankings-'.$this->site->id;
    }

    public function handle(): void
    {
        $connection = $this->site->searchConsoleConnection;
        if (! $connection?->is_active) {
            return;
        }

        $google = $connection->googleConnection;
        if (! $google?->is_active) {
            return;
        }

        $siteUrl = $connection->property_url;
        $endDate = now()->subDays(3)->format('Y-m-d');
        $startDate = now()->subDays(3)->format('Y-m-d');

        try {
            $service = new GoogleSearchConsoleService($google);
            $queries = $service->getTopQueries($siteUrl, $startDate, $endDate, 200);

            if (empty($queries)) {
                return;
            }

            // Get tracked keywords for this site
            $trackedHashes = SeoKeywordRanking::where('site_id', $this->site->id)
                ->where('is_tracked', true)
                ->select('keyword_hash')
                ->distinct()
                ->pluck('keyword_hash')
                ->toArray();

            $records = [];
            $today = now()->format('Y-m-d');

            foreach ($queries as $q) {
                $keyword = $q['query'] ?? $q['keys'][0] ?? null;
                if (! $keyword) {
                    continue;
                }

                $hash = md5(mb_strtolower(trim($keyword)));
                $isTracked = in_array($hash, $trackedHashes, true);

                $records[] = [
                    'site_id' => $this->site->id,
                    'keyword' => mb_substr($keyword, 0, 500),
                    'keyword_hash' => $hash,
                    'url' => isset($q['url']) ? mb_substr($q['url'], 0, 2048) : null,
                    'position' => round((float) ($q['position'] ?? 0), 2),
                    'clicks' => (int) ($q['clicks'] ?? 0),
                    'impressions' => (int) ($q['impressions'] ?? 0),
                    'ctr' => round((float) ($q['ctr'] ?? 0), 4),
                    'recorded_date' => $today,
                    'is_tracked' => $isTracked,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }

            // Delete existing records for today to avoid duplicates
            SeoKeywordRanking::where('site_id', $this->site->id)
                ->where('recorded_date', $today)
                ->delete();

            foreach (array_chunk($records, 100) as $chunk) {
                SeoKeywordRanking::insert($chunk);
            }

            Log::info('Keyword rankings fetched', ['site_id' => $this->site->id, 'keywords' => count($records)]);
        } catch (\Throwable $e) {
            Log::warning('Keyword rankings fetch failed', ['site_id' => $this->site->id, 'error' => $e->getMessage()]);
        }
    }
}
