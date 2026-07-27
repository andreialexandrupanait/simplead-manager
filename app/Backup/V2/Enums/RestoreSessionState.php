<?php

declare(strict_types=1);

namespace App\Backup\V2\Enums;

/**
 * Explicit FSM states for a V2 restore session.
 *
 * Happy path (per docs/backup/TARGET-ARCHITECTURE.md, "Lifecycle state machines"):
 *   requested → validating_backup → pre_restore_backup → downloading → decrypting →
 *   verifying → maintenance → database_restore → file_restore → cleanup →
 *   post_restore_validation → completed
 *
 * Side states: rollback · failed
 *
 * The legal transition graph lives in App\Backup\V2\StateMachine\RestoreStateMachine.
 */
enum RestoreSessionState: string
{
    // Happy path
    case Requested = 'requested';
    case ValidatingBackup = 'validating_backup';
    case PreRestoreBackup = 'pre_restore_backup';
    case Downloading = 'downloading';
    case Decrypting = 'decrypting';
    case Verifying = 'verifying';
    case Maintenance = 'maintenance';
    case DatabaseRestore = 'database_restore';
    case FileRestore = 'file_restore';
    case Cleanup = 'cleanup';
    case PostRestoreValidation = 'post_restore_validation';
    case Completed = 'completed';

    // Side states
    case Rollback = 'rollback';
    case Failed = 'failed';

    /**
     * Terminal states — no further transition is legal once reached.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            default => false,
        };
    }

    /**
     * A session is "active" (in flight) as long as it has not reached a terminal
     * state. rollback is still active (it drives toward a terminal outcome).
     */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * Forward-progress working states (not side states, not terminal).
     *
     * @return list<self>
     */
    public static function processing(): array
    {
        return [
            self::ValidatingBackup,
            self::PreRestoreBackup,
            self::Downloading,
            self::Decrypting,
            self::Verifying,
            self::Maintenance,
            self::DatabaseRestore,
            self::FileRestore,
            self::Cleanup,
            self::PostRestoreValidation,
        ];
    }

    /**
     * States after which the site may have been mutated, so a rollback path is
     * legal (used to build the transition graph).
     *
     * @return list<self>
     */
    public static function mutating(): array
    {
        return [
            self::Maintenance,
            self::DatabaseRestore,
            self::FileRestore,
            self::Cleanup,
            self::PostRestoreValidation,
        ];
    }

    /**
     * The set of states this state may legally transition to.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return \App\Backup\V2\StateMachine\RestoreStateMachine::transitionsFrom($this);
    }
}
