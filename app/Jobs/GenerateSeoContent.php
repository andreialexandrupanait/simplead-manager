<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\SeoContentStatus;
use App\Models\SeoContent;
use App\Services\JobTracker;
use App\Services\SeoContentAiService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class GenerateSeoContent implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 600;

    public array $backoff = [60, 120];

    public function __construct(
        public SeoContent $seoContent,
    ) {}

    public function uniqueId(): string
    {
        return 'seo-content-'.$this->seoContent->id;
    }

    public function handle(): void
    {
        $trackerKey = $this->uniqueId();
        JobTracker::start($trackerKey, 'Starting article generation...');

        try {
            app(SeoContentAiService::class)->generateArticle($this->seoContent, $trackerKey);
            JobTracker::complete($trackerKey, 'Article generated successfully.');
        } catch (\Throwable $e) {
            $this->seoContent->update(['status' => SeoContentStatus::Failed]);
            JobTracker::fail($trackerKey, 'Generation failed: '.$e->getMessage());

            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $this->seoContent->update(['status' => SeoContentStatus::Failed]);

        Log::error("SEO content generation failed for #{$this->seoContent->id}: ".($exception?->getMessage() ?? 'Unknown error'));
    }
}
