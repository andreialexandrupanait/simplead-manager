<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SeoContentStatus;
use App\Models\SeoContent;
use App\Services\WordPressApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishSeoContent implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public array $backoff = [30, 60];

    public function __construct(
        public SeoContent $seoContent,
    ) {}

    public function uniqueId(): string
    {
        return 'seo-publish-'.$this->seoContent->id;
    }

    public function handle(): void
    {
        $site = $this->seoContent->site;
        if (! $site) {
            $this->seoContent->update(['status' => SeoContentStatus::Failed]);
            Log::warning("PublishSeoContent: No site assigned for content #{$this->seoContent->id}");

            return;
        }

        $api = new WordPressApiService($site);

        $result = $api->createPost([
            'title' => $this->seoContent->title,
            'content' => $this->seoContent->content,
            'slug' => $this->seoContent->slug,
            'status' => 'draft',
            'meta_title' => $this->seoContent->title,
            'meta_description' => $this->seoContent->meta_description,
        ]);

        $postId = $result['post_id'] ?? null;

        if ($postId) {
            $this->seoContent->update([
                'wp_post_id' => $postId,
                'status' => SeoContentStatus::Published,
                'published_at' => now(),
            ]);

            Log::info("PublishSeoContent: Published content #{$this->seoContent->id} as WP post #{$postId}");
        } else {
            $this->seoContent->update(['status' => SeoContentStatus::Failed]);
            Log::warning("PublishSeoContent: No post_id returned for content #{$this->seoContent->id}");
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->seoContent->update(['status' => SeoContentStatus::Failed]);
        Log::error("PublishSeoContent failed for #{$this->seoContent->id}: ".($exception?->getMessage() ?? 'Unknown'));
    }
}
