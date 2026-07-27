<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Storage;

use App\Backup\V2\Enums\BackupErrorCode;
use App\Backup\V2\Storage\HardenedMultipartUploader;
use App\Backup\V2\Storage\InMemoryProgressStore;
use App\Backup\V2\Storage\MultipartUploadException;
use App\Backup\V2\Storage\ObjectLayout;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Real-S3 (lab MinIO) coverage for the hardened multipart uploader. No mocks for
 * the storage itself — every assertion is against actual MinIO state.
 */
#[Group('minio')]
class HardenedMultipartUploaderTest extends TestCase
{
    use InteractsWithLabMinio;

    /** 5 MiB parts (S3 minimum) so a small object still spans several parts. */
    private const PART = 5 * 1024 * 1024;

    private array $tmpFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMinio();
    }

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
        $this->cleanupMinio();
        parent::tearDown();
    }

    private function file(int $bytes): string
    {
        return $this->tmpFiles[] = $this->makeRandomFile($bytes);
    }

    private function uploader(
        InMemoryProgressStore $store,
        ?\Closure $onPartConfirmed = null,
        int $maxAttempts = 4,
        ?\Aws\S3\S3Client $client = null,
    ): HardenedMultipartUploader {
        return new HardenedMultipartUploader(
            client: $client ?? $this->minioClient(),
            bucket: $this->bucket,
            progress: $store,
            partSize: self::PART,
            maxAttempts: $maxAttempts,
            baseRetryMs: 1,          // keep tests fast
            maxRetryMs: 4,
            presignedTtlSeconds: 600,
            logger: null,
            onPartConfirmed: $onPartConfirmed,
            sleeper: static fn (int $ms) => null, // no real sleeping in tests
        );
    }

    // ---- Scenario 1: full multipart upload; size + sha256 correct ----------

    public function test_1_full_multipart_upload_has_correct_size_and_sha256(): void
    {
        $bytes = (int) (self::PART * 3 + 123_456); // 4 parts (3 full + tail)
        $local = $this->file($bytes);
        $expectedSha = hash_file('sha256', $local);
        $key = $this->testPrefix.'/database/dump.sql.gz';

        $store = new InMemoryProgressStore;
        $result = $this->uploader($store)->upload($local, $key);

        $this->assertSame(4, $result->partCount(), 'object should span 4 parts');
        $this->assertSame($bytes, $result->size);
        $this->assertSame($expectedSha, $result->sha256);

        // Assert against real MinIO, not just the result object.
        $this->assertSame($bytes, $this->remoteSize($key));
        $this->assertSame($expectedSha, $this->remoteSha256($key));

        // Progress cleared + no dangling multipart after a clean complete.
        $this->assertNull($store->getUploadId($key));
        $this->assertSame(0, $this->inProgressUploadCount($this->testPrefix));
    }

    // ---- Scenario 2: interrupt mid-upload -> resume without re-upload -------

    public function test_2_resume_does_not_reupload_confirmed_parts(): void
    {
        $bytes = (int) (self::PART * 4 + 1000); // 5 parts
        $local = $this->file($bytes);
        $expectedSha = hash_file('sha256', $local);
        $key = $this->testPrefix.'/files/big.tar';

        // Shared, durable progress store survives the "crash".
        $store = new InMemoryProgressStore;

        // Run 1: crash right after part 2 is durably confirmed.
        $crash = function (int $partNumber): void {
            if ($partNumber === 2) {
                throw new RuntimeException('simulated worker crash after part 2');
            }
        };
        $run1 = $this->uploader($store, onPartConfirmed: $crash);

        try {
            $run1->upload($local, $key);
            $this->fail('expected the injected crash to abort run 1');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('simulated worker crash', $e->getMessage());
        }

        // After the crash: parts 1+2 confirmed, upload still in progress (NOT aborted).
        $this->assertSame(2, $run1->partsUploadedThisRun());
        $this->assertCount(2, $store->confirmedParts($key));
        $this->assertNotNull($store->getUploadId($key), 'UploadId must persist for resume');
        $uploadIdRun1 = $store->getUploadId($key);
        $this->assertSame(1, $this->inProgressUploadCount($this->testPrefix), 'multipart still in progress for resume');

        // Run 2: resume with the SAME store. Must skip parts 1+2, upload 3+4+5.
        $run2 = $this->uploader($store);
        $result = $run2->upload($local, $key);

        // uploadId reuse is proven transitively: had run 2 re-initiated, it would
        // have re-uploaded all 5 parts (partsResumed == 0). Instead it resumes 2.
        $this->assertNotEmpty($uploadIdRun1);
        $this->assertSame(2, $run2->partsResumedThisRun(), 'parts 1+2 resumed, not re-uploaded');
        $this->assertSame(3, $run2->partsUploadedThisRun(), 'only parts 3+4+5 uploaded on resume');

        // Grand total PutPart across both runs == exactly the number of parts.
        $totalPut = $run1->partsUploadedThisRun() + $run2->partsUploadedThisRun();
        $this->assertSame(5, $totalPut, 'no part uploaded more than once (2 + 3 == 5)');

        // Final object correct on MinIO.
        $this->assertSame($bytes, $this->remoteSize($key));
        $this->assertSame($expectedSha, $this->remoteSha256($key));
        $this->assertSame(0, $this->inProgressUploadCount($this->testPrefix), 'no dangling upload after resume+complete');
    }

    // ---- Scenario 3: reaper aborts abandoned uploads (no dangling) ----------

    public function test_3_reaper_aborts_abandoned_uploads_and_leaves_no_dangling(): void
    {
        $client = $this->minioClient();
        $key = $this->testPrefix.'/files/abandoned.bin';

        // Abandon a multipart upload: initiate + upload one part, then walk away.
        $uploadId = (string) $client->createMultipartUpload([
            'Bucket' => $this->bucket, 'Key' => $key,
        ])['UploadId'];
        $client->uploadPart([
            'Bucket' => $this->bucket, 'Key' => $key, 'UploadId' => $uploadId,
            'PartNumber' => 1, 'Body' => random_bytes(self::PART),
        ]);

        $this->assertSame(1, $this->inProgressUploadCount($this->testPrefix), 'abandoned upload is in-progress');

        $store = new InMemoryProgressStore;
        $reaper = $this->uploader($store);

        // Nothing reaped when the threshold predates the upload.
        $none = $reaper->reapAbandonedUploads($this->testPrefix, new DateTimeImmutable('-1 hour'));
        $this->assertSame(0, $none, 'upload newer than threshold must survive');
        $this->assertSame(1, $this->inProgressUploadCount($this->testPrefix));

        // Reap everything older than "now" (the abandoned upload qualifies).
        $reaped = $reaper->reapAbandonedUploads($this->testPrefix, new DateTimeImmutable('+1 minute'));
        $this->assertSame(1, $reaped, 'reaper aborted the abandoned upload');
        $this->assertSame(0, $this->inProgressUploadCount($this->testPrefix), 'ListMultipartUploads == 0 dangling');
    }

    // ---- Scenario 4: per-part checksum mismatch detected ----------

    public function test_4a_transient_etag_corruption_is_detected_and_retried(): void
    {
        $bytes = (int) (self::PART * 2 + 10); // 3 parts
        $local = $this->file($bytes);
        $key = $this->testPrefix.'/files/etag-once.bin';

        $client = $this->minioClient();
        $this->corruptEtagOnPart($client, part: 2, times: 1); // corrupt only first attempt

        $store = new InMemoryProgressStore;
        $result = $this->uploader($store, client: $client)->upload($local, $key);

        // Part 2 failed checksum once then succeeded → object still correct.
        $this->assertSame($bytes, $this->remoteSize($key));
        $this->assertSame(hash_file('sha256', $local), $this->remoteSha256($key));
        $this->assertSame(3, $result->partCount());
    }

    public function test_4b_persistent_etag_corruption_fails_cleanly_no_dangling(): void
    {
        $bytes = (int) (self::PART * 2 + 10); // 3 parts
        $local = $this->file($bytes);
        $key = $this->testPrefix.'/files/etag-always.bin';

        $client = $this->minioClient();
        $this->corruptEtagOnPart($client, part: 2); // corrupt every attempt

        $store = new InMemoryProgressStore;

        try {
            $this->uploader($store, maxAttempts: 3, client: $client)->upload($local, $key);
            $this->fail('expected a definitive checksum failure');
        } catch (MultipartUploadException $e) {
            $this->assertSame(BackupErrorCode::UploadFailed, $e->errorCode);
            $this->assertSame(2, $e->partNumber);
        }

        // Clean abort: progress cleared, zero dangling multipart uploads.
        $this->assertNull($store->getUploadId($key));
        $this->assertSame(0, $this->inProgressUploadCount($this->testPrefix), 'aborted cleanly, no dangling');
    }

    // ---- Scenario 5: transient S3 error -> retry w/ backoff -> success ------

    public function test_5_transient_error_on_a_part_is_retried_then_succeeds(): void
    {
        $bytes = (int) (self::PART * 2 + 5); // 3 parts
        $local = $this->file($bytes);
        $key = $this->testPrefix.'/database/transient.sql.gz';

        $client = $this->minioClient(); // retries=0 → our layer handles it
        $this->failFirstAttemptOnPart($client, part: 3);

        $store = new InMemoryProgressStore;
        $result = $this->uploader($store, client: $client)->upload($local, $key);

        $this->assertSame($bytes, $this->remoteSize($key));
        $this->assertSame(hash_file('sha256', $local), $this->remoteSha256($key));
        $this->assertSame(3, $result->partCount());
        $this->assertSame(0, $this->inProgressUploadCount($this->testPrefix));
    }

    // ---- Short-TTL presigned part URL (vs S3Driver's 4h) -------------------

    public function test_presigned_part_url_uses_short_configured_ttl(): void
    {
        $store = new InMemoryProgressStore;
        $uploader = new HardenedMultipartUploader(
            client: $this->minioClient(),
            bucket: $this->bucket,
            progress: $store,
            partSize: self::PART,
            presignedTtlSeconds: 600,
        );

        $url = $uploader->presignPartUrl($this->testPrefix.'/files/x', 'UPLOAD-ID', 1);

        parse_str((string) parse_url($url, PHP_URL_QUERY), $q);
        $this->assertSame('600', $q['X-Amz-Expires'] ?? null, 'TTL must be the short configured value, not 14400 (4h)');
        $this->assertNotSame('14400', $q['X-Amz-Expires'] ?? null);
    }

    // ---- ObjectLayout drives the keys used above --------------------------

    public function test_object_layout_keys_upload_under_tenant_prefix(): void
    {
        $layout = new ObjectLayout(1, 2, 3, $this->testPrefix.'/clients/{client_id}/sites/{site_id}/backups/{backup_id}');
        $local = $this->file((int) (self::PART + 1));
        $key = $layout->database('dump.sql.gz');

        $store = new InMemoryProgressStore;
        $this->uploader($store)->upload($local, $key);

        $this->assertStringEndsWith('/clients/1/sites/2/backups/3/database/dump.sql.gz', $key);
        $this->assertSame(filesize($local), $this->remoteSize($key));
    }
}
