<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\DatabaseHealthService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckDatabaseHealthJob implements ShouldQueue, ShouldBeUnique
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
        return 'db-health-' . $this->site->id;
    }

    public function handle(): void
    {
        try {
            DatabaseHealthService::check($this->site);
        } catch (\Exception $e) {
            Log::warning("Database health check failed for site {$this->site->id}: {$e->getMessage()}");
            throw $e;
        }
    }
}
