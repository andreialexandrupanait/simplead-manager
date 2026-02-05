<?php

namespace App\Jobs;

use App\Models\Site;
use App\Services\CoreFileIntegrityService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CheckCoreFileIntegrity implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(
        public Site $site,
    ) {}

    public function handle(): void
    {
        CoreFileIntegrityService::check($this->site);
    }
}
