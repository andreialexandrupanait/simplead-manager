<?php

declare(strict_types=1);

namespace App\Livewire\Traits;

use App\Models\MaintenancePlan;
use App\Models\Site;
use App\Services\MaintenancePlanService;

trait WithMaintenancePlanApply
{
    public ?int $applyingPlanId = null;

    public string $siteSearch = '';

    public array $selectedSiteIds = [];

    public bool $selectAll = false;

    public bool $applyModules = true;

    public bool $applySecurity = true;

    public bool $applyTweaks = true;

    /** Batch id of the most recent fleet apply, for progress polling (P1-60). */
    public ?string $applyBatchId = null;

    public function startApply(int $planId): void
    {
        $plan = MaintenancePlan::with('planModules')->findOrFail($planId);

        $this->applyingPlanId = $planId;
        $this->selectedSiteIds = [];
        $this->selectAll = false;
        $this->siteSearch = '';
        $this->applyModules = $plan->include_modules;
        $this->applySecurity = $plan->include_security;
        $this->applyTweaks = $plan->include_tweaks;
        $this->view = 'apply';
    }

    public function applyPlan(): void
    {
        if (auth()->user()?->isViewer()) {
            abort(403, 'Viewers cannot manage maintenance plans.');
        }
        if (empty($this->selectedSiteIds)) {
            $this->dispatch('notify', type: 'error', message: 'Please select at least one site.');

            return;
        }

        $plan = MaintenancePlan::with('planModules')->find($this->applyingPlanId);
        if (! $plan) {
            $this->dispatch('notify', type: 'error', message: 'Plan not found.');

            return;
        }

        $sections = [];
        if ($this->applyModules) {
            $sections[] = 'modules';
        }
        if ($this->applySecurity) {
            $sections[] = 'security';
        }
        if ($this->applyTweaks) {
            $sections[] = 'tweaks';
        }

        if (empty($sections)) {
            $this->dispatch('notify', type: 'error', message: 'Please select at least one section to apply.');

            return;
        }

        $scopedQuery = Site::query()
            ->when(! auth()->user()->isAdmin(), fn ($q) => $q->where('user_id', auth()->id()));
        $targets = $scopedQuery->whereIn('id', $this->selectedSiteIds)->get();

        if ($targets->isEmpty()) {
            $this->dispatch('notify', type: 'error', message: 'No valid target sites found.');

            return;
        }

        // P1-60: applying now enqueues one job per site and returns immediately,
        // so a large fleet can't tie up the web worker or time out mid-apply.
        $result = app(MaintenancePlanService::class)->applyToSites($plan, $targets, $sections);

        $this->backToList();
        $this->applyBatchId = $result['batch_id'];

        $this->dispatch(
            'notify',
            type: 'success',
            message: "Plan '{$plan->name}' queued for {$result['queued']} site(s). Applying in the background…",
        );
    }

    /**
     * Live progress of the most recent fleet apply, for the polling banner.
     *
     * @return array{total: int, done: int, failed: int, plan: string, complete: bool}|null
     */
    public function applyProgress(): ?array
    {
        if (! $this->applyBatchId) {
            return null;
        }

        return MaintenancePlanService::progress($this->applyBatchId);
    }

    public function dismissApplyProgress(): void
    {
        $this->applyBatchId = null;
    }

    public function updatedSelectAll(bool $value): void
    {
        if ($value) {
            $this->selectedSiteIds = $this->sites->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        } else {
            $this->selectedSiteIds = [];
        }
    }

    public function updatedSiteSearch(): void
    {
        $this->siteSearch = substr(trim($this->siteSearch), 0, 100);
        unset($this->sites);
    }
}
