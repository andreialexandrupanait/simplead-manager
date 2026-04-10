<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\KeywordResearchResult;
use App\Models\Site;
use App\Services\GoogleAutocompleteService;
use App\Services\JobTracker;
use App\Services\KeywordClusteringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunKeywordResearch implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(
        public KeywordResearchResult $result,
    ) {}

    public function uniqueId(): string
    {
        return 'keyword-research-'.$this->result->id;
    }

    public function handle(): void
    {
        $trackerKey = $this->uniqueId();
        JobTracker::start($trackerKey, 'Starting keyword research...');

        $autocomplete = app(GoogleAutocompleteService::class);
        $clustering = app(KeywordClusteringService::class);

        // Get expanded suggestions
        JobTracker::progress($trackerKey, 10, 'Fetching autocomplete suggestions...');
        $suggestions = $autocomplete->getExpandedSuggestions(
            $this->result->seed_keyword,
            $this->result->language,
            $this->result->country,
        );

        // Try to overlay GSC data if site is connected
        $gscData = [];
        if ($this->result->site_id) {
            JobTracker::progress($trackerKey, 70, 'Fetching Search Console data...');
            $gscData = $this->fetchGscData($this->result->site_id);
        }

        // Cluster keywords
        JobTracker::progress($trackerKey, 85, 'Clustering keywords...');
        $clusters = $clustering->cluster($suggestions);

        $this->result->update([
            'suggestions' => $suggestions,
            'gsc_data' => $gscData,
            'clusters' => $clusters,
        ]);

        JobTracker::complete($trackerKey, 'Research complete — '.count($suggestions).' keywords found.');
    }

    private function fetchGscData(int $siteId): array
    {
        try {
            $site = Site::find($siteId);
            if (! $site) {
                return [];
            }

            $connection = $site->searchConsoleConnection;
            if (! $connection) {
                return [];
            }

            // Get cached top queries if available
            $cache = $site->searchConsoleCaches()
                ->where('data_type', 'top_queries')
                ->latest('fetched_at')
                ->first();

            if ($cache) {
                $rows = $cache->data['rows'] ?? [];

                return collect($rows)->map(fn ($row) => [
                    'keyword' => $row['keys'][0] ?? '',
                    'clicks' => $row['clicks'] ?? 0,
                    'impressions' => $row['impressions'] ?? 0,
                    'ctr' => round(($row['ctr'] ?? 0) * 100, 2),
                    'position' => round($row['position'] ?? 0, 1),
                ])->take(100)->all();
            }

            return [];
        } catch (\Throwable $e) {
            Log::debug("KeywordResearch GSC fetch failed: {$e->getMessage()}");

            return [];
        }
    }

    public function failed(?\Throwable $exception): void
    {
        Log::error("RunKeywordResearch failed for #{$this->result->id}: ".($exception?->getMessage() ?? 'Unknown'));
    }
}
