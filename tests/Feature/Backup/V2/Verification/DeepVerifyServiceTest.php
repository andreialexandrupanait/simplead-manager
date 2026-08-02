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

    /**
     * The SQL check used to read the segment whole and gzdecode it whole, holding
     * both in memory at once. SQL compresses about ten to one, so a segment from
     * the `fast` profile decodes to hundreds of megabytes — past a worker's limit.
     * Every fixture it had ever been given was a few hundred bytes, so the suite
     * could not tell the difference between "valid" and "small enough to survive".
     *
     * Driven through reflection deliberately: what needs pinning is the file-level
     * algorithm, and routing a 200 MB dump through MinIO to reach it would test
     * the object store instead.
     */
    public function test_a_large_dump_is_validated_without_being_held_in_memory(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'bigdump_');

        // ~200 MB of SQL once decoded, but the token only appears at the very end,
        // so a short-circuit on the first window cannot fake a pass.
        $gz = gzopen($path, 'wb1');
        $filler = str_repeat("-- padding padding padding padding padding\n", 24_000); // ~1 MB
        for ($i = 0; $i < 200; $i++) {
            gzwrite($gz, $filler);
        }
        gzwrite($gz, "INSERT INTO `wp_options` VALUES (1);\n");
        gzclose($gz);

        memory_reset_peak_usage();
        $before = memory_get_peak_usage(true);
        $valid = $this->callIsValidSqlGzip($path);
        $growth = memory_get_peak_usage(true) - $before;

        $this->assertTrue($valid, 'a large but perfectly good dump must validate');
        $this->assertLessThan(
            32 * 1024 * 1024,
            $growth,
            'the check must stream the dump, not inflate it whole (grew by '.$growth.' bytes)',
        );

        @unlink($path);
    }

    /**
     * gzopen reads a non-gzip file straight through as plain text. Streaming the
     * read must not quietly turn "this segment is not even compressed" into a pass.
     */
    public function test_a_plain_text_file_is_not_accepted_as_a_dump(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'plaindump_');
        file_put_contents($path, "CREATE TABLE `wp_posts` (id int);\n");

        $this->assertFalse(
            $this->callIsValidSqlGzip($path),
            'an uncompressed file is not a valid gzip segment, whatever it contains',
        );

        @unlink($path);
    }

    /**
     * The read is windowed, so a token can land across a window edge. Without the
     * carried overlap this dump would be declared invalid.
     */
    public function test_a_token_split_across_a_read_window_is_still_found(): void
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'splitdump_');
        $window = 1024 * 1024;

        // Land "INSERT INTO" so that it straddles the first window boundary.
        $prefix = str_repeat('x', $window - 5);
        $gz = gzopen($path, 'wb1');
        gzwrite($gz, $prefix.'INSERT INTO `wp_options` VALUES (1);');
        gzclose($gz);

        $this->assertTrue(
            $this->callIsValidSqlGzip($path),
            'a token straddling a window boundary must still be found',
        );

        @unlink($path);
    }

    private function callIsValidSqlGzip(string $path): bool
    {
        $method = new \ReflectionMethod(DeepVerifyService::class, 'isValidSqlGzip');

        return (bool) $method->invoke(new DeepVerifyService, $path);
    }
}
