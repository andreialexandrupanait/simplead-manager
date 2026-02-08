<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\SecurityScanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunSecurityScan implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 300;
    public array $backoff = [60, 180];

    public function __construct(
        public Site $site,
    ) {}

    public function uniqueId(): string
    {
        return 'security-scan-' . $this->site->id;
    }

    public function handle(): void
    {
        SecurityScanService::scan($this->site);
    }
}
