<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
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
        JobTracker::start($this->uniqueId(), 'Running security scan...');
        SecurityScanService::scan($this->site, $this->uniqueId());

        // Update next scan time from security monitor
        $monitor = $this->site->securityMonitor;
        if ($monitor) {
            $monitor->update([
                'last_scan_at' => now(),
                'next_scan_at' => now()->addMinutes($monitor->interval_minutes),
            ]);
        }

        CircuitBreakerService::recordSuccess($this->site);
        JobTracker::complete($this->uniqueId(), 'Security scan complete');
    }

    public function failed(?\Throwable $exception): void
    {
        CircuitBreakerService::recordFailure($this->site, $exception?->getMessage() ?? 'Security scan failed');
        JobTracker::fail($this->uniqueId(), 'Security scan failed: ' . ($exception?->getMessage() ?? 'Unknown error'));
    }
}
