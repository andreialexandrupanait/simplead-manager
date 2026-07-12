<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\VulnerabilityCheckService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckPluginVulnerabilities implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    public int $uniqueFor = 900; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'check-plugin-vulnerabilities';
    }

    public function handle(): void
    {
        $sites = Site::where('is_connected', true)->get();

        $totalNew = 0;
        $totalFixed = 0;

        foreach ($sites as $site) {
            try {
                $result = VulnerabilityCheckService::check($site);
                $totalNew += $result['new'];
                $totalFixed += $result['fixed'];
            } catch (\Throwable $e) {
                Log::warning("Vulnerability check failed for site {$site->name}: {$e->getMessage()}");
            }
        }

        Log::info("Daily vulnerability check complete: {$totalNew} new, {$totalFixed} fixed across {$sites->count()} sites");
    }
}
