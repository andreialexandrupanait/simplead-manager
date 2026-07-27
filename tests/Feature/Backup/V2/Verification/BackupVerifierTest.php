<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Verification;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\BackupVerification;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Verification\BackupVerifier;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\V2\Storage\InteractsWithLabMinio;
use Tests\Feature\Backup\V2\Support\ManualBackupWriter;
use Tests\TestCase;

/**
 * P6 "verify-at-creation": a completed backup produces a PASSED create-verification
 * (and a verified_at stamp for retention); a backup with a missing / wrong-size
 * object produces a CORRUPT record and is NOT verified.
 */
#[Group('minio')]
class BackupVerifierTest extends TestCase
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

    public function test_complete_backup_verifies_passed_and_stamps_verified_at(): void
    {
        $session = $this->makeSession();
        $layout = $this->layoutFor($session);
        ManualBackupWriter::write($this->minioClient(), $this->bucket, $layout, fileChunks: 2, dbSegments: 1);

        $verification = (new BackupVerifier)->verifyOnComplete($session, $this->minioClient(), $this->bucket, $layout);

        $this->assertTrue($verification->passed(), 'a whole backup must pass create-verification');
        $this->assertSame(BackupVerification::KIND_CREATE, $verification->kind);
        $this->assertSame(3, $verification->object_count);
        $this->assertNotNull($verification->composite_checksum);
        $this->assertNotNull($session->fresh()->verified_at, 'a passing verification stamps verified_at');

        // Persisted, and discoverable as the passing create-verification.
        $this->assertDatabaseHas('backup_verifications', [
            'backup_session_id' => $session->id,
            'kind' => 'create',
            'status' => 'passed',
        ]);
    }

    public function test_missing_object_is_corrupt_and_not_verified(): void
    {
        $session = $this->makeSession();
        $layout = $this->layoutFor($session);
        ManualBackupWriter::write($this->minioClient(), $this->bucket, $layout, fileChunks: 2, dbSegments: 1);

        // Wipe one declared object from storage.
        $this->minioClient()->deleteObject(['Bucket' => $this->bucket, 'Key' => $layout->files('chunk_1.zip')]);

        $verification = (new BackupVerifier)->verifyOnComplete($session, $this->minioClient(), $this->bucket, $layout);

        $this->assertSame(BackupVerification::STATUS_CORRUPT, $verification->status);
        $this->assertFalse($verification->passed());
        $this->assertNull($session->fresh()->verified_at, 'a corrupt backup must NOT be verified');
        $this->assertNotEmpty($verification->checks['missing_objects'] ?? []);
    }

    public function test_wrong_size_object_is_corrupt(): void
    {
        $session = $this->makeSession();
        $layout = $this->layoutFor($session);
        ManualBackupWriter::write($this->minioClient(), $this->bucket, $layout, fileChunks: 1, dbSegments: 1);

        // Overwrite an object with a different-size body (manifest still claims the old size).
        $this->minioClient()->putObject([
            'Bucket' => $this->bucket,
            'Key' => $layout->files('chunk_0.zip'),
            'Body' => 'x',
        ]);

        $verification = (new BackupVerifier)->verifyOnComplete($session, $this->minioClient(), $this->bucket, $layout);

        $this->assertSame(BackupVerification::STATUS_CORRUPT, $verification->status);
        $this->assertNull($session->fresh()->verified_at);
        $this->assertNotEmpty($verification->checks['size_mismatches'] ?? []);
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
            'idempotency_key' => 'verify-'.uniqid('', true),
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
