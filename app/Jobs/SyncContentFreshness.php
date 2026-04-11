<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\SiteContent;
use App\Services\WordPressApiServiceFactory;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncContentFreshness implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    private const STALE_THRESHOLD_DAYS = 180;

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'sync-content-freshness-' . $this->site->id;
    }

    public function handle(): void
    {
        if (! $this->site->is_connected) {
            return;
        }

        try {
            $api = app(WordPressApiServiceFactory::class)->make($this->site);
            $result = $api->getContentFreshness(500);
            $items = $result['items'] ?? [];

            $wpIds = [];

            foreach ($items as $item) {
                $modifiedAt = Carbon::parse($item['modified_at']);
                $daysSince = (int) $modifiedAt->diffInDays(now());

                SiteContent::updateOrCreate(
                    ['site_id' => $this->site->id, 'wp_post_id' => $item['id']],
                    [
                        'title' => $item['title'],
                        'type' => $item['type'],
                        'status' => $item['status'],
                        'url' => $item['url'] ?? null,
                        'word_count' => $item['word_count'] ?? 0,
                        'published_at' => Carbon::parse($item['published_at']),
                        'modified_at' => $modifiedAt,
                        'author_name' => $item['author'] ?? null,
                        'days_since_modified' => $daysSince,
                        'is_stale' => $daysSince > self::STALE_THRESHOLD_DAYS,
                        'checked_at' => now(),
                    ]
                );

                $wpIds[] = $item['id'];
            }

            // Remove posts that no longer exist on WP
            if (! empty($wpIds)) {
                SiteContent::where('site_id', $this->site->id)
                    ->whereNotIn('wp_post_id', $wpIds)
                    ->delete();
            }
        } catch (\Throwable $e) {
            Log::warning("Content freshness sync failed for site {$this->site->name}: {$e->getMessage()}");
        }
    }
}
