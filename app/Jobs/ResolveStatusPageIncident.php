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

class ResolveStatusPageIncident implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 30;

    public int $uniqueFor = 90; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public array $backoff = [15, 30];

    public function __construct(
        public Site $site,
    ) {}

    public function uniqueId(): string
    {
        return 'status-resolve-'.$this->site->id;
    }

    public function handle(): void
    {
        $statusPages = StatusPage::whereHas('incidents', function ($q) {
            $q->where('site_id', $this->site->id)
                ->where('auto_created', true)
                ->where('status', '!=', 'resolved');
        })->get();

        foreach ($statusPages as $statusPage) {
            StatusPageService::resolveAutoIncident($statusPage, $this->site);
        }
    }
}
