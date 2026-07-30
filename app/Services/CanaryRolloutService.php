<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\EvaluateCanaryRollout;
use App\Jobs\QueueSiteSafeUpdatesJob;
use App\Models\Site;
use Illuminate\Support\Collection;

/**
 * SPEC §7.2 Rule 1 — staged rollout of a fleet update.
 *
 *   aplică pe 2 site-uri canar, așteaptă 30 min
 *   rulează smoke check pe URL-urile cheie
 *   dacă trece → aplică pe restul selecției
 *   dacă pică → rollback + task
 *
 * The rollback half already exists: RunSafeUpdate rolls a site back on its own
 * when post-update checks fail. What was missing is the STAGING — proving the
 * update on two sites before it touches the other twenty-eight.
 *
 * Flag-gated: with `updates.smart_rules_enabled` off, callers fan out to every
 * selected site immediately, exactly as before this class existed.
 */
class CanaryRolloutService
{
    /** How many sites go first (SPEC: "2 site-uri canar"). */
    public const CANARY_COUNT = 2;

    /** How long the canaries are observed before the rest follow (SPEC: 30 min). */
    public const OBSERVATION_MINUTES = 30;

    public function __construct(
        private readonly UpdateSmokeCheckService $smokeChecks,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('updates.smart_rules_enabled', false);
    }

    /**
     * Start a staged rollout over $sites. Returns the two groups so the caller can
     * report accurately ("2 now, 12 in 30 minutes").
     *
     * @param  Collection<int, Site>  $sites  eligible sites, already filtered by the caller
     * @return array{canaries: list<int>, deferred: list<int>}
     */
    public function start(Collection $sites, ?int $userId = null): array
    {
        $ordered = $this->rankByBlastRadius($sites);

        $canaries = $ordered->take(self::CANARY_COUNT);
        $deferred = $ordered->slice(self::CANARY_COUNT);

        foreach ($canaries as $site) {
            QueueSiteSafeUpdatesJob::dispatch($site, $userId);
        }

        $canaryIds = $canaries->pluck('id')->map(fn ($id) => (int) $id)->values()->all();
        $deferredIds = $deferred->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        // Nothing to hold back — a one- or two-site selection is its own canary.
        if ($deferredIds !== []) {
            EvaluateCanaryRollout::dispatch($canaryIds, $deferredIds, $userId)
                ->delay(now()->addMinutes(self::OBSERVATION_MINUTES));
        }

        return ['canaries' => $canaryIds, 'deferred' => $deferredIds];
    }

    /**
     * Do the canaries still look healthy to a visitor?
     *
     * Two independent signals, both of which must hold: no safe update on the
     * canary failed or rolled back during the observation window, and the key URLs
     * still pass the smoke check.
     *
     * @param  Collection<int, Site>  $canaries
     * @return array{passed: bool, reasons: list<string>}
     */
    public function verify(Collection $canaries): array
    {
        $reasons = [];

        foreach ($canaries as $site) {
            // 'failed' is the terminal status for both an update that broke and one
            // that broke and was rolled back (SafeUpdateService records the rollback
            // in the notification, not in a separate status).
            $broken = $site->safeUpdates()
                ->where('status', 'failed')
                ->where('created_at', '>=', now()->subMinutes(self::OBSERVATION_MINUTES + 15))
                ->count();

            if ($broken > 0) {
                $reasons[] = "{$site->name}: {$broken} safe update(s) failed or rolled back.";

                continue;
            }

            try {
                $smoke = $this->smokeChecks->runKeyUrls($site);
            } catch (\Throwable $e) {
                // Fail-closed: an un-runnable check is not a passing check.
                $reasons[] = "{$site->name}: smoke check could not run ({$e->getMessage()}).";

                continue;
            }

            if (! $smoke['passed']) {
                $failed = implode(', ', array_column(
                    array_filter($smoke['urls'], fn (array $u): bool => ! $u['passed']),
                    'url'
                ));
                $reasons[] = "{$site->name}: smoke check failed on {$failed}.";
            }
        }

        return ['passed' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * Order sites so the least damaging ones go first: a store taking orders is a
     * worse place to discover a broken update than a brochure site. Within a tier
     * the order is by id, so a rollout is reproducible and explainable.
     *
     * @param  Collection<int, Site>  $sites
     * @return Collection<int, Site>
     */
    private function rankByBlastRadius(Collection $sites): Collection
    {
        return $sites
            ->sortBy(fn (Site $site): array => [
                $this->sellsOnline($site) ? 1 : 0,
                (int) $site->id,
            ])
            ->values();
    }

    /**
     * Detection is free — it reads the plugin inventory the connector already
     * syncs, the same signal the Woo health sweep uses.
     */
    private function sellsOnline(Site $site): bool
    {
        return $site->sitePlugins()
            ->where('slug', 'woocommerce')
            ->where('is_active', true)
            ->exists();
    }
}
