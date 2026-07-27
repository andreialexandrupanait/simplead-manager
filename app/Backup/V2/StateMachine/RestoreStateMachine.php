<?php

declare(strict_types=1);

namespace App\Backup\V2\StateMachine;

use App\Backup\V2\Enums\RestoreSessionState as S;
use App\Backup\V2\Exceptions\IllegalStateTransition;

/**
 * Legal transition graph for a V2 restore session.
 *
 * Follows the ordered happy path from docs/backup/TARGET-ARCHITECTURE.md:
 *   requested → validating_backup → pre_restore_backup → downloading → decrypting →
 *   verifying → maintenance → database_restore → file_restore → cleanup →
 *   post_restore_validation → completed
 *
 * Rules encoded:
 *  - Every processing state may fail.
 *  - Once the restore starts mutating the site (maintenance onward), a rollback
 *    path becomes legal to return the site to its pre_restore snapshot.
 *  - rollback resolves to failed (the restore did not complete, but the site is
 *    safe) — never to completed.
 *  - completed / failed are terminal.
 *  - A transition to the current state is an idempotent no-op.
 */
final class RestoreStateMachine
{
    public const NAME = 'restore';

    /**
     * @return array<string, list<S>> keyed by state value
     */
    private static function graph(): array
    {
        $forward = [
            S::Requested->value => S::ValidatingBackup,
            S::ValidatingBackup->value => S::PreRestoreBackup,
            S::PreRestoreBackup->value => S::Downloading,
            S::Downloading->value => S::Decrypting,
            S::Decrypting->value => S::Verifying,
            S::Verifying->value => S::Maintenance,
            S::Maintenance->value => S::DatabaseRestore,
            S::DatabaseRestore->value => S::FileRestore,
            S::FileRestore->value => S::Cleanup,
            S::Cleanup->value => S::PostRestoreValidation,
            S::PostRestoreValidation->value => S::Completed,
        ];

        $mutating = S::mutating();
        $graph = [];

        foreach (S::processing() as $state) {
            $edges = [$forward[$state->value], S::Failed];
            if (in_array($state, $mutating, true)) {
                $edges[] = S::Rollback;
            }
            $graph[$state->value] = $edges;
        }

        // requested: forward or fail.
        $graph[S::Requested->value] = [S::ValidatingBackup, S::Failed];

        // rollback resolves the site to its safe pre-restore point → failed.
        $graph[S::Rollback->value] = [S::Failed];

        // Terminal states.
        $graph[S::Completed->value] = [];
        $graph[S::Failed->value] = [];

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
