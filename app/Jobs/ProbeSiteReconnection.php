<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Notifications\NotificationService;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Read-only reconnect probe for a site that was flagged is_connected = false.
 *
 * It performs a single, strictly read-only `getInfo()` (GET /info) call against
 * the connector. It never writes to the WordPress host and never mutates remote
 * state. On success it flips the site back to connected so the normal scheduled
 * pipelines (backups, security scans, performance tests, sync) resume; on
 * failure it is a no-op and the site stays disconnected until the next probe.
 */
class ProbeSiteReconnection implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public int $uniqueFor = 90; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public function __construct(
        public Site $site,
    ) {
        $this->onQueue('sync');
    }

    public function uniqueId(): string
    {
        return 'reconnect-probe-'.$this->site->id;
    }

    public function handle(WordPressApiServiceFactory $factory): void
    {
        $this->site->refresh();

        // Already recovered (e.g. a manual "Sync now") â nothing to do.
        if ($this->site->is_connected) {
            return;
        }

        try {
            // Strictly read-only: GET /info. No writes to the WP host.
            $factory->make($this->site)->getInfo();
        } catch (\Throwable $e) {
            Log::info("Reconnect probe: site {$this->site->id} still unreachable â {$e->getMessage()}");

            return;
        }

        $this->site->update([
            'is_connected' => true,
            'last_synced_at' => now(),
        ]);

        Log::info("Reconnect probe: site {$this->site->id} ({$this->site->name}) recovered â reconnected");

        NotificationService::notifySiteEvent(
            $this->site,
            'site_reconnected',
            'Site Reconnected',
            "SimpleAd re-established the connection to {$this->site->name}. Backups, security scans and sync have resumed.",
            [
                'Site' => $this->site->name,
                'URL' => $this->site->url,
            ],
            'info',
        );
    }
}
