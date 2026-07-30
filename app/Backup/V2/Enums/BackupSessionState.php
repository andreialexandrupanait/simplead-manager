<?php

declare(strict_types=1);

namespace App\Backup\V2\Enums;

/**
 * Explicit FSM states for a V2 backup session.
 *
 * Happy path (per docs/backup/TARGET-ARCHITECTURE.md, "Lifecycle state machines"):
 *   requested → capability_check → inventory → database_export → file_diff →
 *   chunking → upload_initializing → uploading → upload_verifying → finalizing →
 *   completed
 *
 * Side states: retry_wait · paused · cancelling → cancelled · failed · corrupt
 *
 * The legal transition graph lives in App\Backup\V2\StateMachine\BackupStateMachine;
 * this enum only knows about individual states and their coarse classification.
 */
enum BackupSessionState: string
{
    // Happy path
    case Requested = 'requested';
    case CapabilityCheck = 'capability_check';
    case Inventory = 'inventory';
    case DatabaseExport = 'database_export';
    case FileDiff = 'file_diff';
    case Chunking = 'chunking';
    case UploadInitializing = 'upload_initializing';
    case Uploading = 'uploading';
    case UploadVerifying = 'upload_verifying';
    case Finalizing = 'finalizing';
    case Completed = 'completed';

    // Side states
    case RetryWait = 'retry_wait';
    case Paused = 'paused';
    case Cancelling = 'cancelling';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
    case Corrupt = 'corrupt';

    /**
     * Terminal states — no further transition is legal once reached.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Cancelled, self::Failed, self::Corrupt => true,
            default => false,
        };
    }

    /**
     * A session is "active" (in flight) as long as it has not reached a terminal
     * state. Side states like retry_wait/paused/cancelling are still active.
     */
    public function isActive(): bool
    {
        return ! $this->isTerminal();
    }

    /**
     * "Processing" states are the forward-progress working states (not side
     * states, not terminal). Used by the state machine to compute resume/branch
     * edges.
     *
     * @return list<self>
     */
    public static function processing(): array
    {
        return [
            self::CapabilityCheck,
            self::Inventory,
            self::DatabaseExport,
            self::FileDiff,
            self::Chunking,
            self::UploadInitializing,
            self::Uploading,
            self::UploadVerifying,
            self::Finalizing,
        ];
    }

    /**
     * The set of states this state may legally transition to (idempotent self
     * transition is handled by the state machine, not listed here).
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return \App\Backup\V2\StateMachine\BackupStateMachine::transitionsFrom($this);
    }
}
