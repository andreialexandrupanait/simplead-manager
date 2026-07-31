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

    /** Connector release that introduced the /content-urls endpoint. */
    private const MIN_CONNECTOR_VERSION = '2.19.2';

    /**
     * @param  int|null  $siteId  Limit the sweep to one site. The monthly run passes
     *                            nothing and covers the fleet; the "scan now" button on
     *                            a site passes its id, so one operator click does not
     *                            crawl every site in the fleet.
     */
    public function __construct(
        public ?int $siteId = null,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        // Per-site runs must not collide with each other or with the fleet sweep.
        return 'check-broken-links'.($this->siteId !== null ? ':'.$this->siteId : '');
    }

    public function handle(BrokenLinkChecker $checker): void
    {
        Site::query()
            ->where('is_connected', true)
            ->when($this->siteId !== null, fn ($q) => $q->whereKey($this->siteId))
            ->each(function (Site $site) use ($checker): void {
                // /content-urls only exists from 2.19.2 on — an older connector would
                // 404 and produce a failed run row instead of a clean skip.
                if (! $site->connectorAtLeast(self::MIN_CONNECTOR_VERSION)) {
                    return;
                }

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
