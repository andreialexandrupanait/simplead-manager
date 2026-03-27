<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\CoreFileIntegrityService;
use App\Services\JobTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckCoreFileIntegrity implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public array $backoff = [30, 60];

    public function __construct(
        public Site $site,
    ) {}

    public function uniqueId(): string
    {
        return 'core-integrity-'.$this->site->id;
    }

    public function handle(CoreFileIntegrityService $service): void
    {
        JobTracker::start($this->uniqueId(), 'Checking core file integrity...');
        $service->check($this->site, $this->uniqueId());
        JobTracker::complete($this->uniqueId(), 'Core file integrity check complete');
    }

    public function failed(?\Throwable $exception): void
    {
        JobTracker::fail($this->uniqueId(), 'Integrity check failed: '.($exception?->getMessage() ?? 'Unknown error'));
    }
}
