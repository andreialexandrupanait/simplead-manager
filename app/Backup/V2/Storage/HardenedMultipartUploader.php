<?php

declare(strict_types=1);

namespace App\Backup\V2\Storage;

use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Support\BackupLogger;
use Aws\S3\S3Client;
use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use RuntimeException;
use Throwable;

/**
 * Hardened S3 multipart uploader for the V2 backup engine (Hetzner S3 in prod,
 * MinIO in the lab). Fixes the #1 production failure class called out in
 * docs/backup/TARGET-ARCHITECTURE.md (multipart transport).
 *
 * UPLOAD MODEL — PULL (server-side, SDK from the manager). The manager holds the
 * S3 credentials and streams a local artifact to S3 with UploadPart, doing all
 * retry / checksum / resume logic in one process. Chosen over push-direct
 * (presigned PUT handed to WordPress) because:
 *   1. Security model: "no permanent S3 creds in WP" — the pull path keeps the
 *      WP plugin thin (it hands chunks to the manager over HMAC; it never sees S3
 *      credentials or long-lived URLs).
 *   2. Reliability: per-part retry+backoff, per-part checksum verification and
 *      resume-from-last-confirmed-part are trivial to enforce server-side; a
 *      presigned push would push that logic into the (much weaker) WP client.
 *   3. Observability: one process owns the whole object's progress + heartbeat.
 * The presigned-per-part path is still provided (presignPartUrl) with a SHORT,
 * just-in-time TTL from config('backup_v2.presigned_ttl_seconds') for a future
 * direct-from-WP transport — never the S3Driver's up-front "+4 hours".
 *
 * Hardening:
 *   - Small configurable parts (config('backup_v2.multipart_part_mb'), clamped to
 *     the S3 5 MiB floor).
 *   - Per-part retry with exponential backoff + full jitter; a failed PART is
 *     retried in place, never the whole object.
 *   - Per-part checksum: sha256 (for our manifest) + Content-MD5 sent so S3
 *     rejects corrupt transfers, and the returned ETag is verified == md5(part).
 *   - Resume: confirmed parts (part_number + etag + sha256 + size) persisted via a
 *     MultipartProgressStore; on resume the SAME UploadId is reused and confirmed
 *     parts are NOT re-read or re-uploaded.
 *   - Clean abort on definitive failure (verified via ListMultipartUploads — no
 *     dangling upload left behind).
 *   - Reaper: reapAbandonedUploads() aborts in-progress uploads older than a
 *     threshold (ListMultipartUploads).
 */
final class HardenedMultipartUploader
{
    /** S3 hard minimum for every part except the last. */
    public const S3_MIN_PART_SIZE = 5 * 1024 * 1024;

    private int $partsUploadedThisRun = 0;

    private int $partsResumedThisRun = 0;

    /**
     * @param  Closure(int $partNumber, array{part_number:int,etag:string,sha256:string,size:int} $part):void|null  $onPartConfirmed
     *                                                                                                                                Called after each part is confirmed+persisted (progress/heartbeat hook; may throw to simulate a crash in tests).
     * @param  Closure(int $ms):void|null  $sleeper  Backoff sleep seam (defaults to usleep); tests pass a no-op.
     */
    public function __construct(
        private readonly S3Client $client,
        private readonly string $bucket,
        private readonly MultipartProgressStore $progress,
        private readonly int $partSize,
        private readonly int $maxAttempts = 5,
        private readonly int $baseRetryMs = 200,
        private readonly int $maxRetryMs = 15000,
        private readonly int $presignedTtlSeconds = 600,
        private readonly ?BackupLogger $logger = null,
        private readonly ?Closure $onPartConfirmed = null,
        private readonly ?Closure $sleeper = null,
    ) {}

    /**
     * Build from config('backup_v2.*'). Credentials/endpoint come from the caller
     * (the resolved S3Client) — this reads only the V2 tuning knobs.
     */
    public static function fromConfig(
        S3Client $client,
        string $bucket,
        MultipartProgressStore $progress,
        ?BackupLogger $logger = null,
        ?Closure $onPartConfirmed = null,
    ): self {
        return new self(
            client: $client,
            bucket: $bucket,
            progress: $progress,
            partSize: max(self::S3_MIN_PART_SIZE, ((int) config('backup_v2.multipart_part_mb', 16)) * 1024 * 1024),
            maxAttempts: (int) config('backup_v2.multipart_max_attempts', 5),
            baseRetryMs: (int) config('backup_v2.multipart_retry_base_ms', 200),
            maxRetryMs: (int) config('backup_v2.multipart_retry_max_ms', 15000),
            presignedTtlSeconds: (int) config('backup_v2.presigned_ttl_seconds', 600),
            logger: $logger,
            onPartConfirmed: $onPartConfirmed,
        );
    }

    public function partsUploadedThisRun(): int
    {
        return $this->partsUploadedThisRun;
    }

    public function partsResumedThisRun(): int
    {
        return $this->partsResumedThisRun;
    }

    /**
     * Upload $localPath to $key via a hardened, resumable multipart upload.
     *
     * Idempotent + resumable: calling again with the same key + progress store
     * reuses the recorded UploadId and skips already-confirmed parts.
     */
    public function upload(string $localPath, string $key): MultipartUploadResult
    {
        if (! is_file($localPath)) {
            throw new RuntimeException("Local file not found for upload: {$localPath}");
        }

        $this->partsUploadedThisRun = 0;
        $this->partsResumedThisRun = 0;

        $totalSize = (int) filesize($localPath);
        $effectivePartSize = max(self::S3_MIN_PART_SIZE, $this->partSize);
        $plan = $this->planParts($totalSize, $effectivePartSize);

        $uploadId = $this->progress->getUploadId($key) ?? $this->begin($key);

        // Index already-confirmed parts (resume): part_number => confirmed row.
        $confirmed = [];
        foreach ($this->progress->confirmedParts($key) as $row) {
            $confirmed[$row['part_number']] = $row;
        }

        $this->log('info', 'multipart upload starting', [
            'key' => $key,
            'bytes' => $totalSize,
            'part_size' => $effectivePartSize,
            'total_parts' => count($plan),
            'already_confirmed' => count($confirmed),
            'resume' => $confirmed !== [],
        ]);

        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException("Unable to open local file for upload: {$localPath}");
        }

        try {
            foreach ($plan as $segment) {
                $partNumber = $segment['part_number'];

                if (isset($confirmed[$partNumber])) {
                    // Resume: do NOT re-read or re-upload a confirmed part.
                    $this->partsResumedThisRun++;

                    continue;
                }

                $body = $this->readChunk($handle, $segment['offset'], $segment['length']);
                $sha256 = hash('sha256', $body);
                $md5Raw = md5($body, true);
                $md5Hex = bin2hex($md5Raw);
                $md5B64 = base64_encode($md5Raw);

                $etag = $this->uploadPartWithRetry($key, $uploadId, $partNumber, $body, $md5B64, $md5Hex);

                $part = [
                    'part_number' => $partNumber,
                    'etag' => $etag,
                    'sha256' => $sha256,
                    'size' => $segment['length'],
                ];

                $this->progress->confirmPart($key, $part);
                $this->partsUploadedThisRun++;
                $confirmed[$partNumber] = $part;

                if ($this->onPartConfirmed !== null) {
                    // Progress/heartbeat hook. Runs AFTER the part is durably
                    // recorded, so a throw here (crash simulation) still leaves a
                    // resumable state on disk.
                    ($this->onPartConfirmed)($partNumber, $part);
                }
            }
        } catch (Throwable $e) {
            fclose($handle);

            if ($e instanceof MultipartUploadException) {
                // Definitive per-part failure → clean abort (no dangling upload).
                $this->abort($key, $uploadId);

                throw $e;
            }

            // Non-upload throwable (e.g. a crash injected by onPartConfirmed):
            // leave the upload + progress INTACT so the next attempt resumes.
            throw $e;
        }

        fclose($handle);

        // Assemble the completed part list from the durable store (authoritative).
        $parts = $this->progress->confirmedParts($key);
        $completed = array_map(static fn (array $p): array => [
            'PartNumber' => $p['part_number'],
            'ETag' => $p['etag'],
        ], $parts);

        try {
            $this->client->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $completed],
            ]);
        } catch (Throwable $e) {
            $this->abort($key, $uploadId);

            throw new MultipartUploadException(
                BackupErrorCode::UploadFailed,
                "completeMultipartUpload failed for {$key}: {$e->getMessage()}",
                $key,
                null,
                $e,
            );
        }

        // Verify the assembled object against what we uploaded.
        $remoteSize = (int) $this->client->headObject([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ])['ContentLength'];

        if ($remoteSize !== $totalSize) {
            throw new MultipartUploadException(
                BackupErrorCode::ChecksumMismatch,
                "Completed object size mismatch for {$key}: expected {$totalSize}, got {$remoteSize}",
                $key,
            );
        }

        $wholeSha256 = hash_file('sha256', $localPath) ?: '';

        $this->progress->clear($key);

        $this->log('info', 'multipart upload completed', [
            'key' => $key,
            'bytes' => $totalSize,
            'parts' => count($parts),
            'parts_uploaded' => $this->partsUploadedThisRun,
            'parts_resumed' => $this->partsResumedThisRun,
        ]);

        return new MultipartUploadResult(
            key: $key,
            size: $totalSize,
            sha256: $wholeSha256,
            etag: $this->multipartEtag($parts),
            parts: $parts,
            partsUploaded: $this->partsUploadedThisRun,
            partsResumed: $this->partsResumedThisRun,
        );
    }

    /**
     * Reap abandoned in-progress multipart uploads under $prefix that were
     * initiated before $olderThan. Returns the number aborted.
     *
     * This is the manager-side ListMultipartUploads reaper: a worker that dies
     * after CreateMultipartUpload but before Complete leaves an in-progress upload
     * (billable, invisible in ListObjects). The reaper sweeps them.
     */
    public function reapAbandonedUploads(string $prefix, DateTimeInterface $olderThan): int
    {
        $aborted = 0;

        foreach ($this->listInProgressUploads($prefix) as $upload) {
            $initiated = $upload['Initiated'] instanceof DateTimeInterface
                ? $upload['Initiated']
                : new DateTimeImmutable((string) $upload['Initiated']);

            if ($initiated < $olderThan) {
                $this->client->abortMultipartUpload([
                    'Bucket' => $this->bucket,
                    'Key' => $upload['Key'],
                    'UploadId' => $upload['UploadId'],
                ]);
                $aborted++;

                $this->log('warning', 'reaped abandoned multipart upload', [
                    'key' => $upload['Key'],
                    'upload_id' => $upload['UploadId'],
                    'initiated' => $initiated->format(DATE_ATOM),
                ]);
            }
        }

        return $aborted;
    }

    /**
     * List in-progress multipart uploads whose key starts with $prefix (paginated).
     *
     * Filtering is done CLIENT-SIDE by design: MinIO's ListMultipartUploads
     * returns an empty set when given a server-side `Prefix` (observed in the lab),
     * whereas real S3/Hetzner honour it. Client-side str_starts_with is correct on
     * every backend, and in-progress multipart uploads are inherently transient and
     * few, so listing + filtering stays cheap. (A future real-S3-only optimisation
     * could push the prefix server-side.)
     *
     * @return list<array{Key: string, UploadId: string, Initiated: mixed}>
     */
    public function listInProgressUploads(string $prefix): array
    {
        $uploads = [];
        $keyMarker = null;
        $uploadIdMarker = null;

        do {
            $params = ['Bucket' => $this->bucket];
            if ($keyMarker !== null) {
                $params['KeyMarker'] = $keyMarker;
            }
            if ($uploadIdMarker !== null) {
                $params['UploadIdMarker'] = $uploadIdMarker;
            }

            $result = $this->client->listMultipartUploads($params);

            foreach ($result['Uploads'] ?? [] as $u) {
                $key = (string) $u['Key'];
                if ($prefix !== '' && ! str_starts_with($key, $prefix)) {
                    continue;
                }
                $uploads[] = [
                    'Key' => $key,
                    'UploadId' => (string) $u['UploadId'],
                    'Initiated' => $u['Initiated'] ?? null,
                ];
            }

            $isTruncated = (bool) ($result['IsTruncated'] ?? false);
            $keyMarker = $result['NextKeyMarker'] ?? null;
            $uploadIdMarker = $result['NextUploadIdMarker'] ?? null;
        } while ($isTruncated);

        return $uploads;
    }

    /**
     * Short-TTL, just-in-time presigned UploadPart URL — the direct-from-WP push
     * path. TTL is config('backup_v2.presigned_ttl_seconds') (minutes, not the
     * S3Driver 4h). Not used by the server-side pull path above.
     */
    public function presignPartUrl(string $key, string $uploadId, int $partNumber): string
    {
        $command = $this->client->getCommand('UploadPart', [
            'Bucket' => $this->bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => $partNumber,
        ]);

        $request = $this->client->createPresignedRequest($command, "+{$this->presignedTtlSeconds} seconds");

        return (string) $request->getUri();
    }

    /**
     * Initiate a fresh multipart upload and persist its UploadId.
     */
    private function begin(string $key): string
    {
        $uploadId = (string) $this->client->createMultipartUpload([
            'Bucket' => $this->bucket,
            'Key' => $key,
        ])['UploadId'];

        $this->progress->putUploadId($key, $uploadId);

        return $uploadId;
    }

    /**
     * Upload one part with exponential backoff + full jitter. A failed part is
     * retried in place; the whole object is never restarted. A returned ETag that
     * does not match md5(part) is treated as a (retryable) corruption.
     */
    private function uploadPartWithRetry(
        string $key,
        string $uploadId,
        int $partNumber,
        string $body,
        string $md5B64,
        string $md5Hex,
    ): string {
        $attempt = 0;
        $last = null;

        while ($attempt < $this->maxAttempts) {
            $attempt++;

            try {
                $result = $this->client->uploadPart([
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'UploadId' => $uploadId,
                    'PartNumber' => $partNumber,
                    'Body' => $body,
                    'ContentLength' => strlen($body),
                    'ContentMD5' => $md5B64,
                ]);

                $etag = strtolower(trim((string) $result['ETag'], '"'));

                if ($etag !== $md5Hex) {
                    // S3/MinIO returned an ETag that doesn't match the bytes we
                    // sent → corrupt transfer. Retryable.
                    throw MultipartUploadException::checksumMismatch($key, $partNumber, $md5Hex, $etag);
                }

                if ($attempt > 1) {
                    $this->log('info', 'part upload succeeded on retry', [
                        'key' => $key, 'part' => $partNumber, 'attempt' => $attempt,
                    ]);
                }

                return $result['ETag'];
            } catch (Throwable $e) {
                $last = $e;

                $this->log('warning', 'part upload attempt failed', [
                    'key' => $key,
                    'part' => $partNumber,
                    'attempt' => $attempt,
                    'max_attempts' => $this->maxAttempts,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt >= $this->maxAttempts) {
                    break;
                }

                $this->sleep($this->backoffMs($attempt));
            }
        }

        throw MultipartUploadException::partFailed($key, $partNumber, $attempt, $last ?? new RuntimeException('unknown'));
    }

    /**
     * Clean abort: abort the upload, then verify via ListMultipartUploads that no
     * dangling in-progress upload remains for this key, and clear progress.
     */
    private function abort(string $key, string $uploadId): void
    {
        try {
            $this->client->abortMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
        } catch (Throwable $e) {
            $this->log('warning', 'abortMultipartUpload raised (continuing to verify)', [
                'key' => $key, 'upload_id' => $uploadId, 'error' => $e->getMessage(),
            ]);
        }

        $dangling = array_filter(
            $this->listInProgressUploads($key),
            static fn (array $u): bool => $u['UploadId'] === $uploadId,
        );

        if ($dangling !== []) {
            $this->log('error', 'multipart upload still dangling after abort', [
                'key' => $key, 'upload_id' => $uploadId,
            ]);
        }

        $this->progress->clear($key);

        $this->log('info', 'multipart upload aborted cleanly', [
            'key' => $key, 'upload_id' => $uploadId, 'dangling' => count($dangling),
        ]);
    }

    /**
     * Partition a file into ordered parts. Every part is >= 5 MiB except the last.
     *
     * @return list<array{part_number: int, offset: int, length: int}>
     */
    private function planParts(int $totalSize, int $partSize): array
    {
        $plan = [];
        $offset = 0;
        $partNumber = 1;

        // Handle a zero-byte object as a single empty part.
        if ($totalSize === 0) {
            return [['part_number' => 1, 'offset' => 0, 'length' => 0]];
        }

        while ($offset < $totalSize) {
            $length = min($partSize, $totalSize - $offset);
            $plan[] = ['part_number' => $partNumber, 'offset' => $offset, 'length' => $length];
            $offset += $length;
            $partNumber++;
        }

        return $plan;
    }

    private function readChunk(mixed $handle, int $offset, int $length): string
    {
        if (fseek($handle, $offset) !== 0) {
            throw new RuntimeException("Failed to seek to offset {$offset}");
        }

        if ($length === 0) {
            return '';
        }

        $buffer = '';
        $remaining = $length;
        while ($remaining > 0) {
            $chunk = fread($handle, $remaining);
            if ($chunk === false || $chunk === '') {
                break;
            }
            $buffer .= $chunk;
            $remaining -= strlen($chunk);
        }

        if (strlen($buffer) !== $length) {
            throw new RuntimeException(
                sprintf('Short read at offset %d: wanted %d, got %d', $offset, $length, strlen($buffer)),
            );
        }

        return $buffer;
    }

    /**
     * Full-jitter exponential backoff: random(0 .. min(max, base * 2^(n-1))).
     */
    private function backoffMs(int $attempt): int
    {
        $ceiling = min($this->maxRetryMs, $this->baseRetryMs * (2 ** ($attempt - 1)));

        return $ceiling <= 0 ? 0 : random_int(0, $ceiling);
    }

    private function sleep(int $ms): void
    {
        if ($ms <= 0) {
            return;
        }

        if ($this->sleeper !== null) {
            ($this->sleeper)($ms);

            return;
        }

        usleep($ms * 1000);
    }

    /**
     * Reconstruct the S3 multipart ETag (md5-of-md5s + "-N") for our records.
     *
     * @param  list<array{part_number: int, etag: string, sha256: string, size: int}>  $parts
     */
    private function multipartEtag(array $parts): string
    {
        $binary = '';
        foreach ($parts as $p) {
            $binary .= hex2bin(strtolower(trim($p['etag'], '"')));
        }

        return md5($binary).'-'.count($parts);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function log(string $level, string $message, array $context = []): void
    {
        $this->logger?->log($level, $message, $context);
    }
}
