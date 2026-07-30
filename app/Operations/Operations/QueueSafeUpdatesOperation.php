<?php

declare(strict_types=1);

namespace App\Operations\Operations;

use App\Jobs\QueueSiteSafeUpdatesJob;
use App\Operations\Contracts\Operation;
use App\Operations\OperationContext;
use App\Operations\OperationDuration;
use App\Operations\OperationResult;

/**
 * SPEC §6.2 — "Update sigur cu rollback" on the shared engine.
 *
 * The engine owns what it is good at: the selection, the eligibility gate with a
 * per-site reason, the queue, the progress tracker and the end-of-batch report.
 * The update itself stays in the pipeline that already knows how to do it safely
 * (backup → update → health check → smoke check → rollback), so this operation
 * hands off to QueueSiteSafeUpdatesJob rather than reimplementing any of it.
 *
 * Consequently verify() does not re-check the site: each item's outcome is
 * verified inside its own RunSafeUpdate, which is also what rolls it back. What
 * this operation guarantees is that eligible sites were queued and ineligible
 * ones were skipped with a reason — not that every update has already landed.
 */
class QueueSafeUpdatesOperation implements Operation
{
    public function key(): string
    {
        return 'updates.safe';
    }

    public function durationClass(): OperationDuration
    {
        return OperationDuration::Long;
    }

    public function isReversible(): bool
    {
        // Each update rolls itself back on a failed health/smoke check.
        return true;
    }

    public function requiresApproval(): bool
    {
        // The approval decision is per plugin, not per batch — it lives in the
        // update decision engine (§7.2), which can hold individual items while
        // the rest of the batch proceeds.
        return false;
    }

    public function prepare(OperationContext $context): OperationResult
    {
        $site = $context->site;

        if (! $site->is_connected) {
            return OperationResult::skipped($site->id, 'Site is not connected.');
        }

        if (! $site->safe_updates_enabled) {
            return OperationResult::skipped($site->id, 'Safe updates are off for this site — an unprotected inline update is never queued from the fleet.');
        }

        $pending = $site->sitePlugins()->where('has_update', true)->count()
            + $site->siteThemes()->where('has_update', true)->count();

        if ($pending === 0) {
            return OperationResult::skipped($site->id, 'Nothing to update.');
        }

        return OperationResult::success($site->id, "{$pending} item(s) pending.", ['pending' => $pending]);
    }

    public function execute(OperationContext $context): OperationResult
    {
        // userId 0 marks a system-initiated run (a scheduled canary release has no
        // acting user); the update pipeline expects null for that, not a user 0.
        QueueSiteSafeUpdatesJob::dispatch($context->site, $context->userId ?: null);

        return OperationResult::success($context->site->id, 'Safe updates queued for this site.');
    }

    public function verify(OperationContext $context): OperationResult
    {
        return OperationResult::verified(
            $context->site->id,
            'Each queued update verifies itself (health + smoke check) and rolls back on failure.',
        );
    }

    public function rollback(OperationContext $context): OperationResult
    {
        return OperationResult::skipped(
            $context->site->id,
            'Rollback happens per update inside the safe-update pipeline, not per batch.',
        );
    }
}
