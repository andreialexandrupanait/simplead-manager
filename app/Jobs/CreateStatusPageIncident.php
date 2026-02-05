<?php

namespace App\Jobs;

use App\Models\Site;
use App\Models\StatusPage;
use App\Services\StatusPageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CreateStatusPageIncident implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 30;

    public function __construct(
        public Site $site,
        public string $reason,
    ) {}

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
