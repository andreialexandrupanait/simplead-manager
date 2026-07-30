<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Verification;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\BackupVerification;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Verification\BackupVerifier;
use App\Backup\V2\Verification\DeepVerifyService;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\V2\Storage\InteractsWithLabMinio;
use Tests\Feature\Backup\V2\Support\ManualBackupWriter;
use Tests\TestCase;

/**
 * P6 sampled DEEP verify against real (lab) storage: opens the file-chunk zips,
 * parses the DB SQL, re-hashes the sampled bytes, recomposes the composite.
 * Corrupting an object's bytes (even keeping the same size) is detected.
 */
#[Group('minio')]
class DeepVerifyServiceTest extends TestCase
{
    use InteractsWithLabMinio;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootMinio();
    }

    protected function tearDown(): void
    {
        $this->cleanupMinio();
        parent::tearDown();
    }

    public function test_deep_verify_passes_opening_archives_and_parsing_db(): void
    {
        $session = $this->makeSession();
        $layout = $this->layoutFor($session);
        ManualBackupWriter::write($this->minioClient(), $this->bucket, $layout, fileChunks: 2, dbSegments: 1);

        // Full-sample deep verify so every object is opened/parsed.
        $verification = (new DeepVerifyService)->deepVerify($session, $this->minioClient(), $this->bucket, $layout, sampleSize: 0);

        $this->assertTrue($verification->passed(), 'a whole backup must pass deep-verify');
        $this->assertSame(BackupVerification::KIND_DEEP, $verification->kind);
        $this->assertSame(3, $verification->sample_size, 'sampleSize=0 opens every object');
        // Both an archive and a DB segment were actually opened.
        $opened = $verification->checks['opened_ok'] ?? [];
        $this->assertNotEmpty($opened);
        $this->assertSame([], $verification->checks['invalid_archives'] ?? ['x']);
        $this->assertSame([], $verification->checks['invalid_sql'] ?? ['x']);
    }

    public function test_deep_verify_detects_byte_corruption(): void
    {
        $session = $this->makeSession();
        $layout = $this->layoutFor($session);
        $manifest = ManualBackupWriter::write($this->minioClient(), $this->bucket, $layout, fileChunks: 1, dbSegments: 1);

        // Find the file object's declared size and overwrite it with SAME-SIZE garbage
        // (size stays consistent → only a full re-hash / archive-open catches it).
        $fileObject = collect($manifest['objects'])->firstWhere('kind', 'files');
        $this->assertNotNull($fileObject);
        $garbage = random_bytes((int) $fileObject['size']);
        $this->minioClient()->putObject([
            'Bucket' => $this->bucket,
            'Key' => $fileObject['key'],
            'Body' => $garbage,
        ]);

        $verification = (new DeepVerifyService)->deepVerify($session, $this->minioClient(), $this->bucket, $layout, sampleSize: 0);

        $this->assertSame(BackupVerification::STATUS_CORRUPT, $verification->status);
        $this->assertNotEmpty($verification->checks['sha_mismatches'] ?? [], 'byte corruption must surface as a sha256 mismatch');
    }

    public function test_deep_verify_composite_matches_create_verification(): void
    {
        $session = $this->makeSession();
        $layout = $this->layoutFor($session);
        ManualBackupWriter::write($this->minioClient(), $this->bucket, $layout, fileChunks: 2, dbSegments: 1);

        $create = (new BackupVerifier)->verifyOnComplete($session, $this->minioClient(), $this->bucket, $layout);
        $deep = (new DeepVerifyService)->deepVerify($session, $this->minioClient(), $this->bucket, $layout, sampleSize: 0);

        $this->assertTrue($create->passed());
        $this->assertTrue($deep->passed());
        $this->assertSame($create->composite_checksum, $deep->composite_checksum, 'deep + create composites agree');
        $this->assertTrue(($deep->checks['composite_matches_create'] ?? false) === true);
    }

    private function makeSession(): BackupSession
    {
        $site = Site::factory()->create();

        return BackupSession::create([
            'site_id' => $site->id,
            'type' => 'full',
            'scope' => ['database' => true, 'files' => true],
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Completed,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'checkpoint' => [],
            'idempotency_key' => 'deep-'.uniqid('', true),
            'format_version' => 'simplead-backup/1',
            'completed_at' => now(),
        ]);
    }

    private function layoutFor(BackupSession $session): ObjectLayout
    {
        return new ObjectLayout(
            1,
            $session->site_id,
            $session->id,
            $this->testPrefix.'/clients/{client_id}/sites/{site_id}/backups/{backup_id}',
        );
    }
}
