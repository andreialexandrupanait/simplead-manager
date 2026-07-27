<?php

declare(strict_types=1);

namespace App\Backup\V2\Retention;

use App\Backup\V2\Models\BackupSession;

/**
 * The immutable outcome of a chain-safe retention pass: which sessions were selected for deletion
 * and which were kept, each with the reason it survived. Returned by ChainRetentionService so the
 * dry-run selection can be inspected/asserted BEFORE anything is deleted, and so an enabled run
 * deletes exactly what the dry-run reported.
 */
final class RetentionPlan
{
    /**
     * @param  list<BackupSession>  $delete  sessions selected for deletion
     * @param  list<array{session:BackupSession, reason:string}>  $keep  sessions kept + why
     */
    public function __construct(
        private readonly array $delete,
        private readonly array $keep,
    ) {}

    /**
     * @return list<BackupSession>
     */
    public function selectedForDeletion(): array
    {
        return $this->delete;
    }

    /**
     * @return list<array{session:BackupSession, reason:string}>
     */
    public function kept(): array
    {
        return $this->keep;
    }

    /**
     * @return list<int>
     */
    public function deleteIds(): array
    {
        return array_map(static fn (BackupSession $s): int => (int) $s->id, $this->delete);
    }

    /**
     * @return list<int>
     */
    public function keepIds(): array
    {
        return array_map(static fn (array $k): int => (int) $k['session']->id, $this->keep);
    }

    /**
     * The keep-reason for a session id, or null if it was not kept.
     */
    public function reasonFor(int $sessionId): ?string
    {
        foreach ($this->keep as $k) {
            if ((int) $k['session']->id === $sessionId) {
                return $k['reason'];
            }
        }

        return null;
    }
}
