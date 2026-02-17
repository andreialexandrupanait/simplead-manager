<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\VulnerabilityCheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckVulnerabilities implements ShouldQueue, ShouldBeUnique
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
        return 'vuln-check-' . $this->site->id;
    }

    public function handle(): void
    {
        VulnerabilityCheckService::check($this->site);
    }

    public function failed(?\Throwable $exception): void
    {
        \Illuminate\Support\Facades\Log::error("Vulnerability check failed for site {$this->site->id}: " . ($exception?->getMessage() ?? 'Unknown error'));
    }
}
