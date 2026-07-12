<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\DatabaseHealthService;
use App\Services\JobTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckDatabaseHealthJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $uniqueFor = 360; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public array $backoff = [30, 60];

    public function __construct(
        public Site $site,
    ) {}

    public function uniqueId(): string
    {
        return 'db-health-'.$this->site->id;
    }

    public function handle(DatabaseHealthService $service): void
    {
        JobTracker::start($this->uniqueId(), 'Checking database health...');

        try {
            $service->check($this->site, $this->uniqueId());
            JobTracker::complete($this->uniqueId(), 'Database health check complete');
        } catch (\Exception $e) {
            Log::warning("Database health check failed for site {$this->site->id}: {$e->getMessage()}");
            throw $e;
        }
    }

    public function failed(?\Throwable $exception): void
    {
        JobTracker::fail($this->uniqueId(), 'Health check failed: '.($exception?->getMessage() ?? 'Unknown error'));
    }
}
