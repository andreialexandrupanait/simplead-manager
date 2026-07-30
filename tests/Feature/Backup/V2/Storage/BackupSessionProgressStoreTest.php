<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Storage;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Storage\BackupSessionProgressStore;
use App\Backup\V2\Storage\HardenedMultipartUploader;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\TestCase;

/**
 * Proves the production progress store — BackupSession.confirmed_parts (jsonb) —
 * drives resume end-to-end against real MinIO: a crash leaves durable progress in
 * the DB row, and a fresh uploader resumes from it without re-uploading.
 */
#[Group('minio')]
class BackupSessionProgressStoreTest extends TestCase
{
    use InteractsWithLabMinio;
    use RefreshDatabase;

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

    private function makeSession(): BackupSession
    {
        $site = Site::factory()->create();

        return BackupSession::create([
            'site_id' => $site->id,
            'type' => 'full',
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Uploading,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'idempotency_key' => 'idem-'.uniqid('', true),
            'format_version' => 'simplead-backup/1',
        ]);
    }

    private function uploader(
        BackupSessionProgressStore $store,
        ?\Closure $onPartConfirmed = null,
    ): HardenedMultipartUploader {
        return new HardenedMultipartUploader(
            client: $this->minioClient(),
            bucket: $this->bucket,
            progress: $store,
            partSize: self::PART,
            maxAttempts: 3,
            baseRetryMs: 1,
            maxRetryMs: 2,
            onPartConfirmed: $onPartConfirmed,
            sleeper: static fn (int $ms) => null,
        );
    }

    public function test_confirmed_parts_jsonb_persists_and_drives_resume(): void
    {
        $bytes = (int) (self::PART * 3 + 777); // 4 parts
        $local = $this->tmpFiles[] = $this->makeRandomFile($bytes);
        $expectedSha = hash_file('sha256', $local);
        $key = $this->testPrefix.'/database/full.sql.gz';

        $session = $this->makeSession();

        // Run 1: crash after part 2 → confirmed_parts must persist in the DB row.
        $store1 = new BackupSessionProgressStore($session);
        $crash = static function (int $partNumber): void {
            if ($partNumber === 2) {
                throw new RuntimeException('crash after part 2');
            }
        };

        try {
            $this->uploader($store1, onPartConfirmed: $crash)->upload($local, $key);
            $this->fail('expected crash');
        } catch (RuntimeException) {
            // expected
        }

        // Re-read the row from the DB: confirmed_parts jsonb holds upload_id + 2 parts.
        $fresh = BackupSession::findOrFail($session->id);
        $this->assertArrayHasKey($key, $fresh->confirmed_parts);
        $this->assertNotEmpty($fresh->confirmed_parts[$key]['upload_id']);
        $this->assertCount(2, $fresh->confirmed_parts[$key]['parts']);

        // Run 2: brand-new store bound to the reloaded row → resumes from jsonb.
        $store2 = new BackupSessionProgressStore($fresh);
        $run2 = $this->uploader($store2);
        $result = $run2->upload($local, $key);

        $this->assertSame(2, $run2->partsResumedThisRun());
        $this->assertSame(2, $run2->partsUploadedThisRun());
        $this->assertSame($bytes, $this->remoteSize($key));
        $this->assertSame($expectedSha, $this->remoteSha256($key));

        // Progress cleared from the jsonb after a clean complete.
        $this->assertArrayNotHasKey($key, BackupSession::findOrFail($session->id)->confirmed_parts ?? []);
        $this->assertSame(0, $this->inProgressUploadCount($this->testPrefix));
    }
}
