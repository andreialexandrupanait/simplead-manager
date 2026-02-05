<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\SecurityScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunSecurityScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;

    public function __construct(
        public Site $site,
    ) {}

    public function handle(): void
    {
        SecurityScanService::scan($this->site);
    }
}
