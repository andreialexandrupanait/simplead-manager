<?php

declare(strict_types=1);

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

class RunSecurityScan implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $uniqueFor = 900; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public array $backoff = [60, 180];

    public function __construct(
        public Site $site,
    ) {
        // P2-70: run on the dedicated `security` supervisor rather than the
        // shared low-priority `default` queue behind long SEO crawls.
        $this->onQueue('security');
    }

    public function uniqueId(): string
    {
        return 'security-scan-'.$this->site->id;
    }

    public function handle(): void
    {
        JobTracker::start($this->uniqueId(), 'Running security scan...');
        app(SecurityScanService::class)->scan($this->site, $this->uniqueId());

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
        JobTracker::fail($this->uniqueId(), 'Security scan failed: '.($exception?->getMessage() ?? 'Unknown error'));
    }
}
