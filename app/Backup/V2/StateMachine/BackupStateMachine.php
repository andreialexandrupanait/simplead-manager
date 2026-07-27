<?php

declare(strict_types=1);

namespace App\Backup\V2\StateMachine;

use App\Backup\V2\Enums\BackupSessionState as S;
use App\Backup\V2\Exceptions\IllegalStateTransition;

/**
 * Legal transition graph for a V2 backup session.
 *
 * Follows the ordered happy path from docs/backup/TARGET-ARCHITECTURE.md and adds
 * the side branches (retry_wait / paused / cancelling → cancelled / failed /
 * corrupt) from the states where they can legally occur.
 *
 * Rules encoded:
 *  - Linear forward path: requested → … → completed.
 *  - Every *processing* state may branch to retry_wait, paused, cancelling, failed.
 *  - The verification-class states (upload_verifying, finalizing) may also go to
 *    corrupt (checksum/manifest failure discovered late).
 *  - retry_wait / paused resume back into any processing state (checkpointed
 *    resume) and may still be cancelled or fail.
 *  - cancelling → cancelled | failed.
 *  - completed / cancelled / failed / corrupt are terminal.
 *  - A transition to the current state is an idempotent no-op (handled here).
 */
final class BackupStateMachine
{
    public const NAME = 'backup';

    /**
     * @return array<string, list<S>> keyed by state value
     */
    private static function graph(): array
    {
        $processing = S::processing();
        $branch = [S::RetryWait, S::Paused, S::Cancelling, S::Failed];

        // Forward edge for each processing state.
        $forward = [
            S::Requested->value => S::CapabilityCheck,
            S::CapabilityCheck->value => S::Inventory,
            S::Inventory->value => S::DatabaseExport,
            S::DatabaseExport->value => S::FileDiff,
            S::FileDiff->value => S::Chunking,
            S::Chunking->value => S::UploadInitializing,
            S::UploadInitializing->value => S::Uploading,
            S::Uploading->value => S::UploadVerifying,
            S::UploadVerifying->value => S::Finalizing,
            S::Finalizing->value => S::Completed,
        ];

        $graph = [];

        // requested: forward + can be cancelled/failed before any work.
        $graph[S::Requested->value] = [S::CapabilityCheck, S::Cancelling, S::Failed];

        foreach ($processing as $state) {
            $edges = [$forward[$state->value], ...$branch];
            // Late integrity failures surface as corrupt.
            if (in_array($state, [S::UploadVerifying, S::Finalizing], true)) {
                $edges[] = S::Corrupt;
            }
            $graph[$state->value] = $edges;
        }

        // retry_wait / paused resume into any processing state, or exit.
        $resume = [...$processing, S::Cancelling, S::Failed];
        $graph[S::RetryWait->value] = $resume;
        $graph[S::Paused->value] = $resume;

        // cancelling resolves to cancelled (or fails while cancelling).
        $graph[S::Cancelling->value] = [S::Cancelled, S::Failed];

        // Terminal states — no outgoing edges.
        $graph[S::Completed->value] = [];
        $graph[S::Cancelled->value] = [];
        $graph[S::Failed->value] = [];
        $graph[S::Corrupt->value] = [];

        return $graph;
    }

    /**
     * @return list<S>
     */
    public static function transitionsFrom(S $from): array
    {
        return self::graph()[$from->value] ?? [];
    }

    public static function canTransition(S $from, S $to): bool
    {
        // Idempotent no-op: staying in the current state is always allowed.
        if ($from === $to) {
            return true;
        }

        foreach (self::transitionsFrom($from) as $allowed) {
            if ($allowed === $to) {
                return true;
            }
        }

        return false;
    }

    /**
     * @throws IllegalStateTransition when the edge is not in the graph.
     */
    public static function assertTransition(S $from, S $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw IllegalStateTransition::for(self::NAME, $from, $to);
        }
    }
}
