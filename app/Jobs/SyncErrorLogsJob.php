<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\ErrorLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncErrorLogsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(
        public Site $site,
    ) {}

    public function handle(): void
    {
        try {
            ErrorLogService::sync($this->site);
        } catch (\Exception $e) {
            Log::warning("Error log sync failed for site {$this->site->id}: {$e->getMessage()}");
            throw $e;
        }
    }
}
