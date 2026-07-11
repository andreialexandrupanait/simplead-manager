<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\ProbeSiteReconnection;
use App\Models\Site;
use Illuminate\Console\Command;

/**
 * Hourly self-healing sweep: for every site currently flagged disconnected,
 * dispatch a read-only reconnect probe. A site that has recovered is flipped
 * back to connected within the hour, so a transient failure never permanently
 * halts its backups/scans/sync.
 */
class ReconnectProbeCommand extends Command
{
    protected $signature = 'sites:reconnect-probe';

    protected $description = 'Probe disconnected sites (read-only) and reconnect any that have recovered';

    public function handle(): int
    {
        $count = 0;

        Site::query()
            ->where('is_connected', false)
            ->each(function (Site $site) use (&$count): void {
                // Stagger dispatch so a large fleet does not stampede the queue.
                ProbeSiteReconnection::dispatch($site)->delay(now()->addSeconds($count * 5));
                $count++;
            });

        $this->info("Dispatched reconnect probes for {$count} disconnected site(s).");

        return self::SUCCESS;
    }
}
