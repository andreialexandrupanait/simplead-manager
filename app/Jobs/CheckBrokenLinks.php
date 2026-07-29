<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\BrokenLinkChecker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Faza 5.3 — monthly broken-link sweep.
 *
 * Fans out over connected sites and runs the broken-link checker for each. One
 * failing site must not abort the sweep, so each site is isolated in its own
 * try/catch. Scheduled monthly (see wiring note) rather than per-site because the
 * scan is heavy and the report cadence is monthly.
 */
class CheckBrokenLinks implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 1800;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return 'check-broken-links';
    }

    public function handle(BrokenLinkChecker $checker): void
    {
        Site::query()
            ->where('is_connected', true)
            ->each(function (Site $site) use ($checker): void {
                try {
                    $checker->check($site);
                } catch (\Throwable $e) {
                    Log::warning('CheckBrokenLinks: site sweep failed', [
                        'site_id' => $site->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
}
