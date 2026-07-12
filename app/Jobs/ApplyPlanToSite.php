<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\MaintenancePlan;
use App\Models\Site;
use App\Services\MaintenancePlanService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * P1-58 / P1-60: apply one maintenance plan to ONE site through the single
 * canonical per-site operation (MaintenancePlanService::applyPlanToSite).
 *
 * The bulk UI dispatches one of these per selected site (instead of applying
 * the whole fleet synchronously inside the Livewire request), and the
 * new-site created hook dispatches one so site creation never blocks on
 * outbound security/tweak pushes. A single failed site is recorded and never
 * aborts the rest of the fleet.
 */
class ApplyPlanToSite implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 120;

    // Release a stale unique lock after a hard kill (≈2.5× timeout).
    public int $uniqueFor = 300;

    /**
     * @param  array<int, string>|null  $sections  Sections to apply; null = all sections the plan carries.
     */
    public function __construct(
        public Site $site,
        public MaintenancePlan $plan,
        public ?array $sections = null,
        public ?string $batchId = null,
    ) {
        $this->onQueue('default');
    }

    public function handle(MaintenancePlanService $service): void
    {
        // Idempotent: always operate on the latest persisted state so a retry
        // (or a racing customization) never acts on a stale snapshot.
        $this->site->refresh();
        $this->plan->loadMissing('planModules');

        $service->applyPlanToSite($this->site, $this->plan, $this->sections);

        if ($this->batchId !== null) {
            MaintenancePlanService::recordProgress($this->batchId, failed: false);
        }
    }

    public function uniqueId(): string
    {
        return 'apply-plan-'.$this->plan->id.'-site-'.$this->site->id;
    }

    public function failed(\Throwable $e): void
    {
        Log::error('ApplyPlanToSite failed', [
            'site_id' => $this->site->id,
            'plan_id' => $this->plan->id,
            'error' => $e->getMessage(),
        ]);

        // Surface a visible, pollable failure state so one site's failure is
        // obvious and does not silently abort the rest of the fleet apply.
        Cache::put(
            MaintenancePlanService::failureKey($this->plan->id, $this->site->id),
            $e->getMessage(),
            now()->addHours(6),
        );

        if ($this->batchId !== null) {
            MaintenancePlanService::recordProgress($this->batchId, failed: true);
        }
    }
}
