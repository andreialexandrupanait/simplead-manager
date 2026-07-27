<?php

declare(strict_types=1);

namespace App\Backup\V2\Chain;

use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Storage\ObjectLayout;
use Aws\S3\S3Client;
use Closure;
use Throwable;

/**
 * Reads a session's manifest.json straight from object storage, resolving each session's own
 * ObjectLayout via the injected factory (a session's key prefix is a function of its
 * client/site/backup ids, which differ per member of a chain).
 *
 * A missing or unparseable manifest is a BROKEN chain — surfaced as BrokenChainException so a
 * restore refuses loudly rather than materialising a partial state.
 */
final class S3ManifestReader implements ManifestReader
{
    /**
     * @param  Closure(BackupSession):ObjectLayout  $layoutFor
     */
    public function __construct(
        private readonly S3Client $s3,
        private readonly string $bucket,
        private readonly Closure $layoutFor,
    ) {}

    public function read(BackupSession $session): array
    {
        $key = ($this->layoutFor)($session)->manifest();

        try {
            $result = $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
            $body = (string) $result['Body'];
        } catch (Throwable $e) {
            throw BrokenChainException::missingManifest($session->id, $e->getMessage());
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw BrokenChainException::missingManifest($session->id, 'manifest is not valid JSON');
        }

        return $decoded;
    }
}
