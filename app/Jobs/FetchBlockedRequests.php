<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\IpFirewallService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FetchBlockedRequests implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(
        public Site $site,
    ) {}

    public function handle(): void
    {
        IpFirewallService::fetchBlockedRequests($this->site);
    }
}
