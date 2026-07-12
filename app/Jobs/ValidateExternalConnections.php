<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\CloudflareConnection;
use App\Models\GoogleConnection;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * P2-64: fan-out dispatcher for external-connection validation.
 *
 * Previously this validated every Google / Cloudflare / storage / WordPress
 * connection inline, serially, with tries=1 — so at fleet scale it exceeded its
 * own timeout and was SIGKILLed mid-run, leaving later connections unchecked.
 *
 * It now only enumerates the connections and dispatches one small, independent
 * {@see ValidateConnection} job per connection. Each has its own bounded timeout
 * and failed() handler, so a single slow connection can no longer kill the batch.
 */
class ValidateExternalConnections implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 60;

    public int $tries = 1;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(): void
    {
        $dispatched = 0;

        GoogleConnection::where('is_active', true)
            ->pluck('id')
            ->each(function (int $id) use (&$dispatched) {
                ValidateConnection::dispatch(ValidateConnection::TYPE_GOOGLE, $id);
                $dispatched++;
            });

        CloudflareConnection::pluck('id')
            ->each(function (int $id) use (&$dispatched) {
                ValidateConnection::dispatch(ValidateConnection::TYPE_CLOUDFLARE, $id);
                $dispatched++;
            });

        StorageDestination::where('is_active', true)
            ->where('type', '!=', 'local')
            ->pluck('id')
            ->each(function (int $id) use (&$dispatched) {
                ValidateConnection::dispatch(ValidateConnection::TYPE_STORAGE, $id);
                $dispatched++;
            });

        Site::where('is_connected', true)
            ->whereNotNull('api_key')
            ->pluck('id')
            ->each(function (int $id) use (&$dispatched) {
                ValidateConnection::dispatch(ValidateConnection::TYPE_WORDPRESS, $id);
                $dispatched++;
            });

        Log::info('External connection validation fanned out', [
            'connections_dispatched' => $dispatched,
        ]);
    }
}
