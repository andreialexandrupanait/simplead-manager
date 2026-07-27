<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Support;

use App\Backup\V2\Chain\BrokenChainException;
use App\Backup\V2\Chain\ManifestReader;
use App\Backup\V2\Models\BackupSession;

/**
 * In-memory ManifestReader for deterministic chain unit tests — maps session id → decoded manifest
 * array, and throws BrokenChainException for an unknown/removed session (mirroring a missing S3
 * manifest) so the broken-chain path is exercisable without object storage.
 */
final class ArrayManifestReader implements ManifestReader
{
    /**
     * @param  array<int, array<string, mixed>>  $manifests  session id => manifest
     */
    public function __construct(private array $manifests = []) {}

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function set(int $sessionId, array $manifest): void
    {
        $this->manifests[$sessionId] = $manifest;
    }

    public function forget(int $sessionId): void
    {
        unset($this->manifests[$sessionId]);
    }

    public function read(BackupSession $session): array
    {
        if (! isset($this->manifests[(int) $session->id])) {
            throw BrokenChainException::missingManifest($session->id, 'no manifest registered');
        }

        return $this->manifests[(int) $session->id];
    }
}
