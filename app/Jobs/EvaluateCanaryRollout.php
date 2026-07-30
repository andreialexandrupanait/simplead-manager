<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Services\CanaryRolloutService;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * SPEC §7.2 Rule 1, second half — 30 minutes after the canaries were updated,
 * decide whether the rest of the selection follows.
 *
 * Fail-closed in every direction: if the canaries cannot be loaded, if the smoke
 * check cannot run, or if anything throws, the deferred sites are NOT updated.
 * A held-back rollout costs a day; a fanned-out broken update costs a fleet.
 *
 * Site IDs travel instead of models so a site deleted during the observation
 * window cannot resurrect a stale serialized record.
 */
class EvaluateCanaryRollout implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * @param  list<int>  $canarySiteIds
     * @param  list<int>  $deferredSiteIds
     */
    public function __construct(
        public readonly array $canarySiteIds,
        public readonly array $deferredSiteIds,
        public readonly ?int $userId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(CanaryRolloutService $rollout): void
    {
        $canaries = Site::whereIn('id', $this->canarySiteIds)->get();

        if ($canaries->isEmpty()) {
            Log::warning('Canary rollout: no canary sites left to evaluate — holding the rest of the batch.', [
                'canary_site_ids' => $this->canarySiteIds,
                'deferred_count' => count($this->deferredSiteIds),
            ]);

            return;
        }

        $verdict = $rollout->verify($canaries);

        if (! $verdict['passed']) {
            $this->reportHold($canaries, $verdict['reasons']);

            return;
        }

        $deferred = Site::whereIn('id', $this->deferredSiteIds)
            ->where('is_connected', true)
            ->where('safe_updates_enabled', true)
            ->get();

        foreach ($deferred as $site) {
            QueueSiteSafeUpdatesJob::dispatch($site, $this->userId);
        }

        Log::info('Canary rollout: canaries healthy, fanning out to the rest of the batch.', [
            'canary_site_ids' => $this->canarySiteIds,
            'released' => $deferred->count(),
        ]);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Site>  $canaries
     * @param  list<string>  $reasons
     */
    private function reportHold($canaries, array $reasons): void
    {
        $held = count($this->deferredSiteIds);
        $detail = implode(' ', $reasons);

        Log::warning('Canary rollout: canaries unhealthy — the rest of the batch was NOT updated.', [
            'canary_site_ids' => $this->canarySiteIds,
            'held' => $held,
            'reasons' => $reasons,
        ]);

        // The operator has to know a fleet update stopped half-way, and on which
        // site it stopped. Notification failures must not fail the job — holding
        // the batch is the important part and it has already happened.
        try {
            NotificationService::notifySiteEventSlim(
                site: $canaries->first(),
                event: 'safe_update_failed',
                summary: "\xF0\x9F\x9A\xA6 Canary check failed — {$held} site(s) held back. {$detail}",
                severity: 'critical',
            );
        } catch (\Throwable $e) {
            Log::warning("Canary rollout: could not notify about the held batch: {$e->getMessage()}");
        }
    }
}
