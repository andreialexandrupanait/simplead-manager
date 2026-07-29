<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Site;
use App\Operations\Contracts\Operation;
use App\Operations\OperationBatch;
use App\Operations\OperationContext;
use App\Operations\OperationOutcome;
use App\Operations\OperationResult;
use App\Services\JobTracker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs one {@see Operation} against one site — the unit the {@see \App\Operations\OperationRunner}
 * fans out. The lifecycle (prepare → execute → verify, with a rollback on a
 * failed reversible op) is driven here so every operation behaves identically.
 *
 * A single site failing NEVER aborts the batch: the outcome is recorded on
 * {@see OperationBatch} and the job returns/handles its own failure. The queue
 * itself is chosen by the runner (from the operation's duration class) at
 * dispatch time via ->onQueue().
 */
class RunOperationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public int $timeout;

    /**
     * @param  class-string<Operation>  $operationClass
     * @param  array<string, mixed>  $params
     */
    public function __construct(
        public string $operationClass,
        public int $siteId,
        public array $params,
        public int $userId,
        public string $runId,
        public string $trackerKey,
    ) {
        $duration = app($operationClass)->durationClass();
        $this->tries = $duration->tries();
        $this->timeout = $duration->timeout();
    }

    public function handle(): void
    {
        /** @var Operation $op */
        $op = app($this->operationClass);

        $site = Site::find($this->siteId);

        if ($site === null) {
            OperationBatch::record($this->runId, OperationOutcome::Skipped, $this->siteId);
            JobTracker::appendLog($this->trackerKey, "Site {$this->siteId} no longer exists — skipped.");

            return;
        }

        $context = new OperationContext(
            $site,
            $this->userId,
            $this->params,
            $this->runId,
            $this->trackerKey,
        );

        $prepared = $op->prepare($context);

        if ($prepared->isSkipped()) {
            OperationBatch::record($this->runId, OperationOutcome::Skipped, $this->siteId);
            JobTracker::appendLog(
                $this->trackerKey,
                "{$site->name}: skipped".($prepared->message ? " — {$prepared->message}" : ''),
            );

            return;
        }

        $result = null;

        try {
            $result = $op->execute($context);

            if ($result->isSuccess()) {
                $result = $op->verify($context);
            }
        } catch (\Throwable $e) {
            $result = OperationResult::failure($this->siteId, $e->getMessage());
        }

        // A failed execute() OR a failed verify() on a reversible operation must
        // be compensated so the site is not left in a half-applied state.
        if ($result->isFailure() && $op->isReversible()) {
            try {
                $op->rollback($context);
                JobTracker::appendLog($this->trackerKey, "{$site->name}: rolled back after failure.");
            } catch (\Throwable $e) {
                JobTracker::appendLog($this->trackerKey, "{$site->name}: rollback failed — {$e->getMessage()}");
            }
        }

        OperationBatch::record($this->runId, $result->outcome, $this->siteId);
        JobTracker::appendLog(
            $this->trackerKey,
            "{$site->name}: {$result->outcome->value}".($result->message ? " — {$result->message}" : ''),
        );
    }

    /**
     * A job that exhausts its retries records a Failure for its site — it must
     * never abort the rest of the batch.
     */
    public function failed(\Throwable $exception): void
    {
        OperationBatch::record($this->runId, OperationOutcome::Failure, $this->siteId);
        JobTracker::appendLog(
            $this->trackerKey,
            "Operation failed for site {$this->siteId}: {$exception->getMessage()}",
        );
    }
}
