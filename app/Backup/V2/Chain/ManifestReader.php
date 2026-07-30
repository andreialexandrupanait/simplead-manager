<?php

declare(strict_types=1);

namespace App\Backup\V2\Chain;

use App\Backup\V2\Models\BackupSession;

/**
 * Loads the decoded manifest.json for a completed backup session. Abstracted so
 * ChainResolver can materialise a chain from real S3 objects in the lab/prod AND
 * from an in-memory map in deterministic unit tests, without the resolver knowing
 * where manifests live.
 *
 * Implementations MUST throw App\Backup\V2\Chain\BrokenChainException when a
 * session's manifest cannot be read (missing / corrupt) — a chain whose manifest
 * is gone is a broken chain.
 */
interface ManifestReader
{
    /**
     * @return array<string, mixed> the decoded manifest.json
     *
     * @throws BrokenChainException when the manifest is missing or unreadable
     */
    public function read(BackupSession $session): array;
}
