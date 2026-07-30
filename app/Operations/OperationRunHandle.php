<?php

declare(strict_types=1);

namespace App\Operations;

/**
 * The result of asking the runner to run an operation: enough for the caller
 * (Livewire) to report progress or drive an approval flow.
 *
 * $status is 'running' once jobs are dispatched, or 'pending_approval' when the
 * operation requires approval and none was given — no jobs exist yet in that
 * case and the caller must later call OperationRunner::approve($runId).
 */
final readonly class OperationRunHandle
{
    public function __construct(
        public string $runId,
        public string $trackerKey,
        public string $status,
        public int $total,
    ) {}
}
