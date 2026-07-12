<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Models\StatusPage;
use App\Services\StatusPageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateStatusPageIncident implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public int $uniqueFor = 90; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public array $backoff = [15, 30];

    public function __construct(
        public Site $site,
        public string $reason,
    ) {}

    public function uniqueId(): string
    {
        return 'status-incident-'.$this->site->id;
    }

    public function handle(): void
    {
        $statusPages = StatusPage::where('auto_incidents', true)
            ->whereHas('statusPageSites', function ($q) {
                $q->where('site_id', $this->site->id);
            })
            ->get();

        foreach ($statusPages as $statusPage) {
            StatusPageService::createAutoIncident($statusPage, $this->site, $this->reason);
        }
    }
}
