<?php

declare(strict_types=1);

namespace App\Operations\Operations;

use App\Models\MaintenancePlan;
use App\Operations\Contracts\Operation;
use App\Operations\OperationContext;
use App\Operations\OperationDuration;
use App\Operations\OperationResult;
use App\Services\MaintenancePlanService;

/**
 * SPEC §6.2 / §9 — apply a maintenance plan (uptime tier, backup cadence, active
 * checks, report cadence) to each selected site.
 *
 * Takes `plan_id` in the operation params; the toolbar's plan picker supplies it.
 * Synchronous, so verify() genuinely confirms the site now carries the plan.
 */
class ApplyMaintenancePlanOperation implements Operation
{
    public function __construct(
        private readonly MaintenancePlanService $plans,
    ) {}

    public function key(): string
    {
        return 'plan.apply';
    }

    public function durationClass(): OperationDuration
    {
        return OperationDuration::Medium;
    }

    public function isReversible(): bool
    {
        return false;
    }

    public function requiresApproval(): bool
    {
        return false;
    }

    public function prepare(OperationContext $context): OperationResult
    {
        if ($this->plan($context) === null) {
            return OperationResult::skipped($context->site->id, 'No such maintenance plan.');
        }

        return OperationResult::success($context->site->id);
    }

    public function execute(OperationContext $context): OperationResult
    {
        $plan = $this->plan($context);

        if ($plan === null) {
            return OperationResult::failure($context->site->id, 'The plan disappeared between prepare and execute.');
        }

        $sections = $context->params['sections'] ?? null;
        $this->plans->applyPlanToSite($context->site, $plan, is_array($sections) ? $sections : null);

        return OperationResult::success($context->site->id, "Plan \"{$plan->name}\" applied.");
    }

    public function verify(OperationContext $context): OperationResult
    {
        $planId = (int) ($context->params['plan_id'] ?? 0);
        $current = (int) ($context->site->fresh()->maintenance_plan_id ?? 0);

        return $current === $planId
            ? OperationResult::verified($context->site->id, 'Site now carries the plan.')
            : OperationResult::failure($context->site->id, 'Site is not on the requested plan after the apply.');
    }

    public function rollback(OperationContext $context): OperationResult
    {
        return OperationResult::skipped($context->site->id, 'The previous plan configuration is not snapshotted, so it cannot be restored.');
    }

    private function plan(OperationContext $context): ?MaintenancePlan
    {
        $planId = (int) ($context->params['plan_id'] ?? 0);

        return $planId > 0 ? MaintenancePlan::with('planModules')->find($planId) : null;
    }
}
