<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\AuditLogService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncAuditLogs implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;
    public array $backoff = [15, 30];

    public function __construct(
        public Site $site,
    ) {}

    public function uniqueId(): string
    {
        return 'audit-sync-' . $this->site->id;
    }

    public function handle(): void
    {
        AuditLogService::sync($this->site);
    }
}
