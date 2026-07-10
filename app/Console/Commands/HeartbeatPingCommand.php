<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Dead-man's switch. Pings an external monitor every scheduler tick; if the
 * scheduler or the whole app dies, the external monitor stops receiving pings
 * and alerts out-of-band. This is the only "who watches the watchman" signal
 * that survives the platform itself going dark.
 */
class HeartbeatPingCommand extends Command
{
    protected $signature = 'monitoring:heartbeat';

    protected $description = 'Ping the external scheduler heartbeat (dead-man\'s switch)';

    public function handle(): int
    {
        $url = config('monitoring.heartbeat_url');

        if (! is_string($url) || $url === '') {
            return self::SUCCESS;
        }

        try {
            Http::timeout(10)->get($url);
        } catch (\Throwable $e) {
            // A failed ping must never disrupt the scheduler; the missing ping
            // is itself the signal the external monitor reacts to.
            Log::warning('Scheduler heartbeat ping failed: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
