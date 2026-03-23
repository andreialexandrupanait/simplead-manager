<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\JobTracker;
use App\Services\PluginAbandonmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckAbandonedPluginsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public array $backoff = [30, 60];

    public function __construct(
        public Site $site,
    ) {}

    public function uniqueId(): string
    {
        return 'abandoned-plugins-'.$this->site->id;
    }

    public function handle(): void
    {
        JobTracker::start($this->uniqueId(), 'Checking for abandoned plugins...');
        PluginAbandonmentService::checkAllForSite($this->site, $this->uniqueId());
        JobTracker::complete($this->uniqueId(), 'Abandoned plugin check complete');
    }

    public function failed(?\Throwable $exception): void
    {
        JobTracker::fail($this->uniqueId(), 'Abandoned check failed: '.($exception?->getMessage() ?? 'Unknown error'));
    }
}
