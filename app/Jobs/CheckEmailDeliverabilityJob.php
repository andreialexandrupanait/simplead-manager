<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\EmailDeliverabilityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckEmailDeliverabilityJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public Site $site,
    ) {}

    public function handle(): void
    {
        try {
            EmailDeliverabilityService::check($this->site);
        } catch (\Exception $e) {
            Log::warning("Email deliverability check failed for site {$this->site->id}: {$e->getMessage()}");
            throw $e;
        }
    }
}
