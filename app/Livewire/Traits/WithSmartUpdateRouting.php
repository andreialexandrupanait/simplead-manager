<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Enums\UpdateRoute;
use App\Models\SafeUpdate;
use App\Models\SitePlugin;
use App\Services\UpdateDecisionService;
use Illuminate\Support\Facades\Log;

/**
 * Faza 6 (SPEC §7.2/§7.3) — flag-gated glue between the pure update-decision
 * engine and the safe-update dispatch points. Behaviour is ADDITIVE and strictly
 * gated on `config('updates.smart_rules_enabled')` (default OFF): with the flag
 * off this code is never reached and the safe-update flow is unchanged.
 */
trait WithSmartUpdateRouting
{
    /**
     * Whether the smart update-decision engine is enabled fleet-wide.
     */
    protected function smartUpdateRulesEnabled(): bool
    {
        return config('updates.smart_rules_enabled') === true;
    }

    /**
     * Score the freshly-created safe update against the decision engine and
     * return the route it should take.
     *
     * AwaitApproval also flips `approval_required = true` so the caller can hold
     * the dispatch and an operator can later approve it.
     *
     * FAIL-SAFE: if the engine throws for any reason we log and fall back to
     * AwaitApproval — never let an engine failure silently auto-apply an update,
     * and never let it break the update flow with an uncaught exception.
     */
    protected function decideSafeUpdateRoute(SafeUpdate $safeUpdate, SitePlugin $plugin): UpdateRoute
    {
        try {
            $route = app(UpdateDecisionService::class)->decide($plugin)->route;
        } catch (\Throwable $e) {
            Log::error(
                "Smart update decision failed for plugin {$plugin->id} on site {$plugin->site_id}: "
                .$e->getMessage().' — holding for approval (fail-safe).'
            );

            $route = UpdateRoute::AwaitApproval;
        }

        if ($route === UpdateRoute::AwaitApproval) {
            $safeUpdate->update(['approval_required' => true]);
        }

        return $route;
    }
}
