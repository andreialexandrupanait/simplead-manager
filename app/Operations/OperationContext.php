<?php

declare(strict_types=1);

namespace App\Operations;

use App\Models\Site;

/**
 * Immutable per-site execution context handed to every phase of an operation
 * (prepare/execute/verify/rollback). Carries the target site, the acting user,
 * the caller-supplied params, and the identifiers that tie this single-site run
 * back to its aggregate batch ({@see OperationBatch}) and progress tracker
 * ({@see \App\Services\JobTracker}).
 */
final readonly class OperationContext
{
    /**
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public Site $site,
        public int $userId,
        public array $params,
        public string $runId,
        public string $trackerKey,
    ) {}
}
