<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\Backup\ConnectorPusher;
use App\Services\Backup\FleetMigrationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Move one site onto the new backup engine, in the background.
 *
 * One job per site rather than one for the fleet: each is a connector push plus a plugin install
 * against somebody else's WordPress, which can take a minute and can fail on its own terms. Bundled
 * together, one slow host would hold up twenty-eight others and one failure would leave the rest in
 * an unknown state.
 *
 * Progress is written where the console can read it, under a run id — the same shape the connector
 * push already uses, so there is one way to watch a fleet-wide operation rather than two.
 */
class MigrateSiteToBackupV2 implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** No retries: a failed migration is a thing to read and decide about, not to repeat blindly. */
    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public Site $site,
        public string $runId,
    ) {
        $this->onQueue('default');
    }

    public function handle(FleetMigrationService $migration, ConnectorPusher $connector): void
    {
        try {
            $result = $migration->migrate($this->site, $connector);
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => $e->getMessage(), 'steps' => []];

            Log::warning('Fleet migration to V2 failed', [
                'site_id' => $this->site->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->record($result);
    }

    /**
     * The job died outright — a timeout, a killed worker. Without this the console would show the
     * site as still running for an hour, which is indistinguishable from a slow host.
     */
    public function failed(?Throwable $e): void
    {
        $this->record([
            'ok' => false,
            'message' => $e?->getMessage() ?? 'The migration job did not finish.',
            'steps' => [],
        ]);
    }

    /**
     * @param  array{ok: bool, message: string, steps: array<string, string>}  $result
     */
    private function record(array $result): void
    {
        $key = self::cacheKey($this->runId);

        // Read-modify-write on a cache key, which is not atomic — two sites finishing in the same
        // instant can lose one entry. Acceptable here and nowhere near the migration itself: the
        // authority on what happened is the site's own state, which the console re-reads. This is
        // only the running commentary.
        $progress = (array) Cache::get($key, []);
        $progress[$this->site->id] = [
            'site' => $this->site->domain,
            'ok' => $result['ok'],
            'message' => $result['message'],
            'steps' => $result['steps'],
            'at' => now()->toIso8601String(),
        ];

        Cache::put($key, $progress, now()->addHour());
    }

    public static function cacheKey(string $runId): string
    {
        return "backup-v2-migration:{$runId}";
    }
}
