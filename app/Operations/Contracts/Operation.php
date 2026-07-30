<?php

declare(strict_types=1);

namespace App\Operations\Contracts;

use App\Operations\OperationContext;
use App\Operations\OperationDuration;
use App\Operations\OperationResult;

/**
 * A fleet operation applied to one site at a time via {@see \App\Jobs\RunOperationJob}.
 *
 * The lifecycle is: prepare() (gate — may Skip), execute() (do the work),
 * verify() (confirm the side effect landed), and rollback() (compensate a
 * failed reversible operation). Implementations MUST be stateless — the runner
 * resolves a fresh instance per site from the container.
 */
interface Operation
{
    /**
     * Stable machine key (e.g. 'cloudflare.purge'). Used by the registry and to
     * persist a pending-approval intent.
     */
    public function key(): string;

    /**
     * Cost class deciding the queue + retry/timeout budget.
     */
    public function durationClass(): OperationDuration;

    /**
     * Whether a failed execute()/verify() can be compensated by rollback().
     */
    public function isReversible(): bool;

    /**
     * Whether the batch must be explicitly approved before any job dispatches.
     */
    public function requiresApproval(): bool;

    /**
     * Gate the operation for this site. Return a Skipped result to exclude the
     * site without treating it as a failure.
     */
    public function prepare(OperationContext $context): OperationResult;

    /**
     * Perform the operation's side effect.
     */
    public function execute(OperationContext $context): OperationResult;

    /**
     * Confirm the side effect actually took hold.
     */
    public function verify(OperationContext $context): OperationResult;

    /**
     * Compensate a failed reversible operation. Only invoked when
     * isReversible() is true and execute()/verify() failed.
     */
    public function rollback(OperationContext $context): OperationResult;
}
