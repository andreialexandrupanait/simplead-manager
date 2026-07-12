<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\JobTracker;
use App\Services\ThemeIntegrityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckThemeIntegrity implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 360; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public array $backoff = [30, 60];

    public function __construct(
        public Site $site,
        public string $themeSlug,
    ) {}

    public function uniqueId(): string
    {
        return "theme-integrity-{$this->site->id}-{$this->themeSlug}";
    }

    public function handle(ThemeIntegrityService $service): void
    {
        $key = $this->uniqueId();
        JobTracker::start($key, "Checking theme '{$this->themeSlug}' integrity...");
        $service->check($this->site, $this->themeSlug, $key);
        JobTracker::complete($key, 'Theme integrity check complete');
    }

    public function failed(?\Throwable $exception): void
    {
        JobTracker::fail(
            $this->uniqueId(),
            'Theme integrity check failed: '.($exception?->getMessage() ?? 'Unknown error')
        );
    }
}
