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

        $result = app(MaintenancePlanService::class)->applyToSites($plan, $targets, $sections);

        $this->backToList();

        $message = "Plan '{$plan->name}' applied to {$result['total']} site(s).";
        if ($result['pushed'] > 0) {
            $message .= " Pushing to {$result['pushed']} connected site(s).";
        }
        if ($result['disconnected'] > 0) {
            $message .= " {$result['disconnected']} disconnected site(s) will receive settings when connected.";
        }
        $this->dispatch('notify', type: 'success', message: $message);
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
