<?php

declare(strict_types=1);

namespace App\Operations;

use App\Jobs\RunOperationJob;
use App\Models\Site;
use App\Operations\Contracts\Operation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The shared engine that fans a single {@see Operation} out across a set of
 * sites — one queued {@see RunOperationJob} per site — and tracks aggregate
 * progress on {@see OperationBatch}.
 *
 * Scoping is the CALLER's responsibility: the runner is handed an already
 * visibility-scoped Collection<Site> (from Livewire's scopedSiteQuery) and only
 * validates it is non-empty. It never queries sites itself except when reviving
 * a persisted approval intent.
 */
class OperationRunner
{
    /** Pending-approval intent TTL. */
    private const INTENT_TTL_HOURS = 6;

    /**
     * Run an operation across the given (already-scoped) sites.
     *
     * If the operation requires approval and none was given, the intent (op key,
     * site ids, params) is persisted and a 'pending_approval' handle is returned
     * WITHOUT dispatching anything. Otherwise one job per site is dispatched onto
     * the operation's duration queue.
     *
     * @param  Collection<int, Site>  $sites
     * @param  array<string, mixed>  $params
     */
    public function run(Operation $op, Collection $sites, array $params, int $userId, bool $approved = false): OperationRunHandle
    {
        if ($sites->isEmpty()) {
            throw new \InvalidArgumentException('OperationRunner::run requires at least one site.');
        }

        if ($op->requiresApproval() && ! $approved) {
            return $this->persistIntent($op, $sites, $params, $userId);
        }

        $runId = (string) Str::uuid();
        $trackerKey = OperationBatch::trackerKey($runId);
        $queue = $op->durationClass()->queue();

        OperationBatch::init($runId, $sites->count(), $op->key());

        foreach ($sites as $site) {
            RunOperationJob::dispatch($op::class, $site->id, $params, $userId, $runId, $trackerKey)
                ->onQueue($queue);
        }

        return new OperationRunHandle($runId, $trackerKey, 'running', $sites->count());
    }

    /**
     * Approve a previously persisted pending-approval batch and dispatch it.
     */
    public function approve(string $runId, int $userId): OperationRunHandle
    {
        $intent = Cache::get(self::intentKey($runId));

        if (! is_array($intent)) {
            throw new \InvalidArgumentException("No pending operation to approve for run {$runId}.");
        }

        Cache::forget(self::intentKey($runId));

        $op = app(OperationRegistry::class)->resolve($intent['op']);
        $sites = Site::whereIn('id', $intent['siteIds'])->get();

        return $this->run($op, $sites, $intent['params'] ?? [], $userId, approved: true);
    }

    /**
     * @param  Collection<int, Site>  $sites
     * @param  array<string, mixed>  $params
     */
    private function persistIntent(Operation $op, Collection $sites, array $params, int $userId): OperationRunHandle
    {
        $runId = (string) Str::uuid();

        Cache::put(self::intentKey($runId), [
            'op' => $op->key(),
            'siteIds' => $sites->pluck('id')->all(),
            'params' => $params,
            'userId' => $userId,
        ], now()->addHours(self::INTENT_TTL_HOURS));

        return new OperationRunHandle(
            $runId,
            OperationBatch::trackerKey($runId),
            'pending_approval',
            $sites->count(),
        );
    }

    private static function intentKey(string $runId): string
    {
        return "operation-intent:{$runId}";
    }
}
