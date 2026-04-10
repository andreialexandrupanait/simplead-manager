<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\JobTracker;
use App\Services\SeoAuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunSeoAudit implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public array $backoff = [60, 120];

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'seo-audit-'.$this->site->id;
    }

    public function handle(): void
    {
        $trackerKey = $this->uniqueId();

        JobTracker::start($trackerKey, 'Running SEO audit...');

        $audit = app(SeoAuditService::class)->audit($this->site, $trackerKey);

        $monitor = $this->site->seoMonitor;
        if ($monitor) {
            $monitor->update([
                'last_audit_at' => now(),
                'next_audit_at' => now()->addMinutes($monitor->interval_minutes),
            ]);
        }

        JobTracker::complete($trackerKey, "SEO audit complete (score: {$audit->score})");
    }

    public function failed(?\Throwable $exception): void
    {
        JobTracker::fail(
            $this->uniqueId(),
            'SEO audit failed: '.($exception?->getMessage() ?? 'Unknown error'),
        );
    }
}
