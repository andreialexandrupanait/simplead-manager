<?php

declare(strict_types=1);

namespace App\Backup\V2\Verification;

use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\BackupVerification;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Support\BackupLogger;
use Aws\S3\S3Client;
use Throwable;

/**
 * "Verify-at-creation" for the V2 backup engine — the cheap, ALWAYS-run check
 * that turns a `completed` backup into a *verified* one.
 *
 * It reads the backup's own control documents from storage and confirms the
 * backup is whole and internally consistent:
 *   - manifest.json parses and lists at least one object,
 *   - _COMPLETE is present (the single source of truth that a backup is whole),
 *   - checksums.json / metadata.json are present,
 *   - EVERY manifest object exists in storage (HEAD) with the exact declared size,
 *   - checksums.json agrees with the manifest on every object's sha256,
 *   - a composite checksum (sha256 over the ordered per-object sha256s) is recorded.
 *
 * A PASSED create-verification is the ONLY thing that stamps
 * `backup_sessions.verified_at`, which ChainRetentionService reads for its
 * keep-last-verified guarantee. A backup with no passing verification is never
 * treated as verified for retention.
 *
 * This is deliberately HEAD-only (no full object download): it is meant to run
 * on every completion with negligible cost. Byte-level content corruption that
 * preserves object size is the job of the sampled DeepVerifyService.
 *
 * Never throws to its caller — a storage/parse failure is recorded as a
 * failed/corrupt verification record so a verifier bug can never turn a good
 * completed backup into a hard error.
 */
final class BackupVerifier
{
    private readonly BackupLogger $logger;

    public function __construct(?BackupLogger $logger = null)
    {
        $this->logger = ($logger ?? new BackupLogger)->forSession('verify', null, null);
    }

    /**
     * Produce (and persist) the create-verification record for a completed backup.
     */
    public function verifyOnComplete(
        BackupSession $session,
        S3Client $s3,
        string $bucket,
        ObjectLayout $layout,
    ): BackupVerification {
        $checks = [];
        $objectCount = 0;
        $composite = null;

        try {
            // (1) manifest present + parseable.
            $manifest = $this->getJson($s3, $bucket, $layout->manifest());
            $objects = is_array($manifest['objects'] ?? null) ? $manifest['objects'] : [];
            $checks['manifest_present'] = true;
            $checks['manifest_objects'] = count($objects);
            if ($objects === []) {
                return $this->record($session, BackupVerification::STATUS_FAILED, 0, null, $checks, 'manifest lists no objects');
            }

            // (2) control documents present.
            foreach ([
                'complete_marker' => $layout->completeMarker(),
                'checksums_present' => $layout->checksums(),
                'metadata_present' => $layout->metadata(),
            ] as $label => $key) {
                if (! $this->head($s3, $bucket, $key)) {
                    $checks[$label] = false;

                    return $this->record($session, BackupVerification::STATUS_CORRUPT, 0, null, $checks, "required control object missing: {$key}");
                }
                $checks[$label] = true;
            }

            // (3) checksums.json cross-check source.
            $checksums = $this->getJson($s3, $bucket, $layout->checksums());
            $checksumObjects = is_array($checksums['objects'] ?? null) ? $checksums['objects'] : [];

            // (4) every manifest object exists with the declared size, and its sha256
            //     agrees with checksums.json. Composite = sha256 over ordered shas.
            $shaConcat = '';
            $missing = [];
            $sizeMismatch = [];
            $shaMismatch = [];

            foreach ($objects as $object) {
                $key = (string) ($object['key'] ?? '');
                $declaredSize = (int) ($object['size'] ?? -1);
                $declaredSha = (string) ($object['sha256'] ?? '');
                $objectCount++;
                $shaConcat .= $declaredSha;

                $remoteSize = $this->headSize($s3, $bucket, $key);
                if ($remoteSize === null) {
                    $missing[] = $key;

                    continue;
                }
                if ($remoteSize !== $declaredSize) {
                    $sizeMismatch[] = ['key' => $key, 'declared' => $declaredSize, 'storage' => $remoteSize];
                }

                $ckSha = (string) ($checksumObjects[$key]['sha256'] ?? '');
                if ($ckSha === '' || ! hash_equals($declaredSha, $ckSha)) {
                    $shaMismatch[] = $key;
                }
            }

            $composite = hash('sha256', $shaConcat);
            $checks['object_count'] = $objectCount;
            $checks['missing_objects'] = $missing;
            $checks['size_mismatches'] = $sizeMismatch;
            $checks['checksum_disagreements'] = $shaMismatch;
            $checks['composite_checksum'] = $composite;

            if ($missing !== []) {
                return $this->record($session, BackupVerification::STATUS_CORRUPT, $objectCount, $composite, $checks, count($missing).' object(s) missing in storage');
            }
            if ($sizeMismatch !== []) {
                return $this->record($session, BackupVerification::STATUS_CORRUPT, $objectCount, $composite, $checks, count($sizeMismatch).' object(s) with a size mismatch');
            }
            if ($shaMismatch !== []) {
                return $this->record($session, BackupVerification::STATUS_FAILED, $objectCount, $composite, $checks, count($shaMismatch).' object(s) where manifest/checksums disagree');
            }

            return $this->record($session, BackupVerification::STATUS_PASSED, $objectCount, $composite, $checks, null);
        } catch (Throwable $e) {
            $checks['exception'] = $e->getMessage();

            return $this->record($session, BackupVerification::STATUS_FAILED, $objectCount, $composite, $checks, $e->getMessage());
        }
    }

    /**
     * @param  array<string,mixed>  $checks
     */
    private function record(
        BackupSession $session,
        string $status,
        int $objectCount,
        ?string $composite,
        array $checks,
        ?string $error,
    ): BackupVerification {
        $verifiedAt = $status === BackupVerification::STATUS_PASSED ? now() : null;

        $verification = new BackupVerification([
            'backup_session_id' => $session->id,
            'kind' => BackupVerification::KIND_CREATE,
            'status' => $status,
            'object_count' => $objectCount,
            'sample_size' => null,
            'composite_checksum' => $composite,
            'checks' => $checks,
            'error' => $error,
            'verified_at' => $verifiedAt,
        ]);
        $verification->save();

        // A PASSED create-verification is the ONLY writer of the retention-facing
        // verified_at stamp. A failed/corrupt verification leaves it untouched, so
        // the backup is never counted as "verified" for keep-last-verified.
        if ($status === BackupVerification::STATUS_PASSED) {
            $session->verified_at = now();
            $session->save();
        }

        $this->logger->log(
            $status === BackupVerification::STATUS_PASSED ? 'info' : 'warning',
            'backup create-verification',
            ['session_id' => $session->id, 'status' => $status, 'object_count' => $objectCount, 'error' => $error],
        );

        return $verification;
    }

    private function head(S3Client $s3, string $bucket, string $key): bool
    {
        return $this->headSize($s3, $bucket, $key) !== null;
    }

    private function headSize(S3Client $s3, string $bucket, string $key): ?int
    {
        try {
            $head = $s3->headObject(['Bucket' => $bucket, 'Key' => $key]);

            return (int) $head['ContentLength'];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function getJson(S3Client $s3, string $bucket, string $key): array
    {
        $result = $s3->getObject(['Bucket' => $bucket, 'Key' => $key]);
        $decoded = json_decode((string) $result['Body'], true);

        return is_array($decoded) ? $decoded : [];
    }
}
