<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Orchestration\BackupRunner;
use App\Backup\V2\Plugin\PluginClient;
use App\Backup\V2\Plugin\SimpleadBackupClient;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Storage\S3ClientFactory;
use App\Models\Site;
use Aws\S3\S3Client;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use RuntimeException;
use Tests\Feature\Backup\V2\Storage\InteractsWithLabMinio;
use Tests\Feature\Backup\V2\Support\FakePluginClient;
use Tests\Feature\Backup\V2\Support\LabBackupFixture;
use Tests\Feature\Backup\V2\Support\RecordingPluginClient;
use Tests\TestCase;
use ZipArchive;

/**
 * End-to-end P2 proof: the FIRST real full backup driven by BackupRunner through
 * the FSM, against the live simplead-backup plugin (spike-wp) and real MinIO. No
 * mocks for storage or transport in the primary test.
 *
 * Lab prerequisites (provisioned by spike/orchestrator/backup-v2-e2e-setup.sh):
 *   - spike-wp reachable at self::wpHost() with simplead-backup active + HMAC key set,
 *   - the deterministic file fixture materialised under LabBackupFixture::SUBDIR,
 *   - lab MinIO reachable (InteractsWithLabMinio).
 * Any missing prerequisite → the test is skipped (never a false failure).
 */
#[Group('minio')]
#[Group('e2e')]
class BackupRunnerE2ETest extends TestCase
{
    use InteractsWithLabMinio;
    use RefreshDatabase;

    private static function wpHost(): string
    {
        // Default is the sam_spike_net container alias; override to the host-published
        // port (http://127.0.0.1:18080) when the runner is on the host network.
        return getenv('BACKUP_ENGINE_V2_LAB_WP_HOST') ?: 'http://sam_spike-spike-wp-1';
    }

    /** 512 KiB → the 40 small files span ~10 file chunks; big.bin is its own chunk. */
    private const FILE_CHUNK_THRESHOLD = 524288;

    /** 2 MiB uncompressed → the WP DB dumps to several segments. */
    private const DB_SEGMENT_BYTES = 2097152;

    protected function setUp(): void
    {
        parent::setUp();

        // Small multipart parts so big.bin (12 MiB) is a real multi-part upload.
        config(['backup_v2.multipart_part_mb' => 5]);

        $this->bootMinio();
        $this->skipUnlessPluginAndFixtureReady();
    }

    protected function tearDown(): void
    {
        $this->cleanupMinio();
        parent::tearDown();
    }

    // ── Test 1: full backup completes, verified, _COMPLETE last, oracle 0/0 ──

    public function test_full_backup_completes_with_verified_objects_and_complete_marker_last(): void
    {
        $session = $this->makeSession('full');
        $layout = $this->layoutFor($session);
        $client = $this->realClient();

        $result = $this->runner($session, $client, $layout)->run();

        // (a) reached completed through the FSM.
        $this->assertSame(BackupSessionState::Completed, $result->state, 'full backup must reach completed');
        $this->assertNotNull($result->completed_at);

        // (b) control objects all present.
        foreach ([$layout->manifest(), $layout->checksums(), $layout->metadata(), $layout->completeMarker()] as $key) {
            $this->assertObjectExists($key);
        }

        $manifest = $this->downloadJson($layout->manifest());
        $this->assertNotEmpty($manifest['objects'], 'manifest must list objects');

        // (c) every manifest object exists on MinIO with matching size + sha256.
        $dbObjects = 0;
        $fileObjects = 0;
        foreach ($manifest['objects'] as $object) {
            $key = $object['key'];
            $this->assertSame((int) $object['size'], $this->remoteSize($key), "size mismatch for {$key}");
            $this->assertSame($object['sha256'], $this->remoteSha256($key), "sha256 mismatch for {$key}");
            $object['kind'] === 'database' ? $dbObjects++ : $fileObjects++;
        }
        $this->assertGreaterThan(0, $dbObjects, 'full backup must include DB segments');
        $this->assertGreaterThan(1, $fileObjects, 'full backup must span several file chunks');

        // (d) checksums.json agrees with the manifest.
        $checksums = $this->downloadJson($layout->checksums());
        foreach ($manifest['objects'] as $object) {
            $this->assertSame($object['sha256'], $checksums['objects'][$object['key']]['sha256']);
        }

        // (e) _COMPLETE is the LAST object written (>= every other object's mtime).
        $completeMtime = $this->lastModified($layout->completeMarker());
        foreach ($this->listAllObjects($layout->listPrefix()) as $key => $mtime) {
            if ($key === $layout->completeMarker()) {
                continue;
            }
            $this->assertLessThanOrEqual(
                $completeMtime->getTimestamp(),
                $mtime->getTimestamp(),
                "_COMPLETE must be written after {$key}",
            );
        }

        // (f) FILE restore oracle: extract every file chunk, compare each file's
        // sha256 to the independently-recomputed ground truth. 0 missing / 0 mismatch.
        [$missing, $mismatched, $verified] = $this->fileRestoreOracle($manifest, $layout);
        $this->assertSame([], $missing, 'restore oracle: files missing from the backup');
        $this->assertSame([], $mismatched, 'restore oracle: files with wrong content');
        $this->assertSame(count(LabBackupFixture::groundTruth()), $verified, 'every fixture file verified');

        // (g) DB segments are valid gzip and structurally complete SQL.
        $this->assertDatabaseSegmentsRestorable($manifest, $layout);

        // (h) manifest carries the required metadata.
        $this->assertSame('full', $manifest['backup_type']);
        $this->assertSame('simplead-backup/1', $manifest['format_version']);
        $this->assertNotEmpty($manifest['exclusion_policy_hash']);
        $this->assertNotEmpty($manifest['scope_hash']);
        $this->assertTrue($manifest['environment']['consistent_snapshot']);
        $this->assertNotEmpty($manifest['environment']['wp_version']);
        $this->assertNotEmpty($manifest['database']['tables']);
    }

    // ── Test 2: empty chunk is skipped (never pulled/uploaded/manifested) ────

    public function test_empty_chunk_is_skipped_and_absent_from_manifest(): void
    {
        // Files-only plan of 4 chunks where chunk index 2 materialises 0 files.
        $fake = new FakePluginClient(chunkCount: 4, emptyIndexes: [2]);
        $session = $this->makeSession('files');
        $layout = $this->layoutFor($session);

        try {
            $result = $this->runner($session, $fake, $layout)->run();
        } finally {
            $fake->cleanup();
        }

        $this->assertSame(BackupSessionState::Completed, $result->state);

        // The empty chunk was never downloaded.
        $this->assertNotContains(2, $fake->downloadedIndexes, 'empty chunk must never be pulled');
        $this->assertEqualsCanonicalizing([0, 1, 3], $fake->downloadedIndexes);

        // It is absent from the manifest objects, and recorded as a skipped chunk.
        $manifest = $this->downloadJson($layout->manifest());
        $fileIndexes = array_map(static fn (array $o): int => (int) $o['chunk_index'], $manifest['objects']);
        $this->assertEqualsCanonicalizing([0, 1, 3], $fileIndexes, 'only non-empty chunks are objects');
        $this->assertContains(2, $manifest['files']['skipped_empty_chunks']);

        // The empty chunk's object key does not exist on MinIO.
        $this->assertObjectMissing($layout->files('chunk_2.zip'));
        $this->assertObjectExists($layout->files('chunk_0.zip'));
    }

    // ── Test 3: crash mid-backup → resume never re-pulls/re-uploads ──────────

    public function test_resume_after_crash_does_not_reupload_confirmed_objects(): void
    {
        $session = $this->makeSession('full');
        $layout = $this->layoutFor($session);

        // Same recorder across both runs → total pull count per chunk is cumulative.
        $recording = new RecordingPluginClient($this->realClient());

        // Crash after the 2nd FILE object is durably confirmed (DB fully done by then).
        $filesConfirmed = 0;
        $crash = function (string $kind) use (&$filesConfirmed): void {
            if ($kind === 'files') {
                $filesConfirmed++;
                if ($filesConfirmed === 2) {
                    throw new RuntimeException('injected worker crash after 2 file objects');
                }
            }
        };

        // Run 1 — crashes mid-uploading.
        try {
            $this->runner($session, $recording, $layout, $crash)->run();
            $this->fail('expected the injected crash to abort run 1');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('injected worker crash', $e->getMessage());
        }

        $session->refresh();
        $this->assertNotSame(BackupSessionState::Completed, $session->state, 'must not be complete after a crash');

        // Snapshot the objects confirmed before the crash (ETag + mtime) to prove
        // they are NOT rewritten on resume.
        $confirmedBefore = $session->confirmed_objects ?? [];
        // DB dumps before files, so at crash (after 2 files) the DB segment(s) plus
        // those 2 files are already confirmed. A minimal fresh WP DB is a single
        // segment, so assert the env-robust floor (>=1 DB segment + 2 files); the
        // real proof is the no-re-upload fingerprint check below, not this count.
        $this->assertGreaterThanOrEqual(1 + 2, count($confirmedBefore), 'DB segment(s) + 2 files confirmed before crash');
        $fingerprintBefore = [];
        foreach ($confirmedBefore as $id => $object) {
            $fingerprintBefore[$id] = $this->objectFingerprint((string) $object['key']);
        }

        // Run 2 — resume the SAME session with the SAME recorder.
        $result = $this->runner($session->fresh(), $recording, $layout)->run();
        $this->assertSame(BackupSessionState::Completed, $result->state, 'resume must complete the backup');

        // No chunk was pulled more than once across the two runs (no double download,
        // hence no double upload — pull-and-free means a re-pull is impossible anyway).
        $counts = [];
        foreach ($recording->downloads as $d) {
            $key = $d['kind'].':'.$d['index'];
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
        foreach ($counts as $key => $n) {
            $this->assertSame(1, $n, "chunk {$key} was pulled {$n} times (expected exactly once)");
        }

        // Objects confirmed before the crash were NOT re-uploaded (identical ETag+mtime).
        foreach ($fingerprintBefore as $id => $fp) {
            $key = (string) $confirmedBefore[$id]['key'];
            $this->assertSame($fp, $this->objectFingerprint($key), "object {$key} was re-uploaded on resume");
        }

        // Final backup is whole.
        $this->assertObjectExists($layout->completeMarker());
        $manifest = $this->downloadJson($layout->manifest());
        [$missing, $mismatched] = $this->fileRestoreOracle($manifest, $layout);
        $this->assertSame([], $missing);
        $this->assertSame([], $mismatched);
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeSession(string $type): BackupSession
    {
        $site = Site::factory()->create();

        return BackupSession::create([
            'site_id' => $site->id,
            'type' => $type,
            'scope' => [
                'database' => $type !== 'files',
                'files' => $type !== 'database',
                'rules' => LabBackupFixture::includeRule(),
                'exclude_tables' => [],
            ],
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Requested,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'checkpoint' => [],
            'idempotency_key' => 'e2e-'.uniqid('', true),
            'format_version' => 'simplead-backup/1',
        ]);
    }

    private function realClient(): SimpleadBackupClient
    {
        return SimpleadBackupClient::lab(self::wpHost());
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

    private function runner(
        BackupSession $session,
        PluginClient $client,
        ObjectLayout $layout,
        ?Closure $fault = null,
    ): BackupRunner {
        return new BackupRunner(
            session: $session,
            client: $client,
            s3: $this->s3(),
            bucket: $this->bucket,
            layout: $layout,
            logger: null,
            fileChunkThreshold: self::FILE_CHUNK_THRESHOLD,
            dbSegmentBytes: self::DB_SEGMENT_BYTES,
            compression: 'store',
            faultAfterObject: $fault,
        );
    }

    private function s3(): S3Client
    {
        return S3ClientFactory::lab()->client();
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array{0:list<string>,1:list<string>,2:int} [missing, mismatched, verified]
     */
    private function fileRestoreOracle(array $manifest, ObjectLayout $layout): array
    {
        $ground = LabBackupFixture::groundTruth();
        $seen = [];
        $mismatched = [];

        foreach ($manifest['objects'] as $object) {
            if ($object['kind'] !== 'files') {
                continue;
            }
            $zipPath = (string) tempnam(sys_get_temp_dir(), 'oracle_');
            $this->s3()->getObject(['Bucket' => $this->bucket, 'Key' => $object['key'], 'SaveAs' => $zipPath]);

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($zipPath, ZipArchive::CHECKCONS) === true, "chunk {$object['key']} is a valid zip");
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $bytes = (string) $zip->getFromIndex($i);
                $seen[$name] = true;
                if (! isset($ground[$name])) {
                    continue; // not part of the closed ground-truth set
                }
                if (! hash_equals($ground[$name]['sha256'], hash('sha256', $bytes))) {
                    $mismatched[] = $name;
                }
            }
            $zip->close();
            @unlink($zipPath);
        }

        $missing = array_values(array_diff(array_keys($ground), array_keys($seen)));

        return [$missing, $mismatched, count($ground) - count($missing) - count($mismatched)];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function assertDatabaseSegmentsRestorable(array $manifest, ObjectLayout $layout): void
    {
        $sql = '';
        $segments = 0;
        foreach ($manifest['objects'] as $object) {
            if ($object['kind'] !== 'database') {
                continue;
            }
            $segments++;
            $gzPath = (string) tempnam(sys_get_temp_dir(), 'dbseg_');
            $this->s3()->getObject(['Bucket' => $this->bucket, 'Key' => $object['key'], 'SaveAs' => $gzPath]);
            $decoded = gzdecode((string) file_get_contents($gzPath));
            $this->assertNotFalse($decoded, "segment {$object['key']} must be valid gzip");
            $sql .= $decoded;
            @unlink($gzPath);
        }

        $this->assertGreaterThan(0, $segments, 'DB must produce segments');
        $this->assertStringContainsString('CREATE TABLE `wp_options`', $sql);
        $this->assertStringContainsString('CREATE TABLE `wp_posts`', $sql);
        $this->assertStringContainsString('INSERT INTO `wp_options`', $sql);
        $this->assertStringContainsString('SET FOREIGN_KEY_CHECKS=0;', $sql);
        $this->assertStringContainsString('End of Simplead Backup dump', $sql);
    }

    // ── MinIO assertion helpers ──────────────────────────────────────────

    private function assertObjectExists(string $key): void
    {
        $this->assertTrue($this->objectExists($key), "expected object present: {$key}");
    }

    private function assertObjectMissing(string $key): void
    {
        $this->assertFalse($this->objectExists($key), "expected object absent: {$key}");
    }

    private function objectExists(string $key): bool
    {
        try {
            $this->minioClient()->headObject(['Bucket' => $this->bucket, 'Key' => $key]);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function downloadJson(string $key): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'json_');
        $this->minioClient()->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $tmp]);
        $decoded = json_decode((string) file_get_contents($tmp), true);
        @unlink($tmp);

        return is_array($decoded) ? $decoded : [];
    }

    private function lastModified(string $key): \DateTimeInterface
    {
        $head = $this->minioClient()->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
        $lm = $head['LastModified'];

        return $lm instanceof \DateTimeInterface ? $lm : new \DateTimeImmutable((string) $lm);
    }

    private function objectFingerprint(string $key): string
    {
        $head = $this->minioClient()->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
        $lm = $head['LastModified'];
        $ts = $lm instanceof \DateTimeInterface ? $lm->getTimestamp() : strtotime((string) $lm);

        return trim((string) $head['ETag'], '"').'@'.$ts;
    }

    /**
     * @return array<string, \DateTimeInterface> key => LastModified
     */
    private function listAllObjects(string $prefix): array
    {
        $out = [];
        $result = $this->minioClient()->listObjectsV2(['Bucket' => $this->bucket, 'Prefix' => $prefix]);
        foreach ($result['Contents'] ?? [] as $o) {
            $lm = $o['LastModified'];
            $out[(string) $o['Key']] = $lm instanceof \DateTimeInterface ? $lm : new \DateTimeImmutable((string) $lm);
        }

        return $out;
    }

    private function skipUnlessPluginAndFixtureReady(): void
    {
        $client = $this->realClient();

        try {
            $caps = $client->capabilities();
        } catch (\Throwable $e) {
            $this->markTestSkipped('simplead-backup plugin not reachable at '.self::wpHost().': '.$e->getMessage());
        }

        if (($caps['plugin']['name'] ?? null) !== 'simplead-backup') {
            $this->markTestSkipped('simplead-backup plugin not active on '.self::wpHost());
        }

        try {
            $inv = $client->filesInventory('preflight_'.uniqid('', true), LabBackupFixture::includeRule(), ['preview' => true]);
        } catch (\Throwable $e) {
            $this->markTestSkipped('files inventory preflight failed: '.$e->getMessage());
        }

        $expected = count(LabBackupFixture::spec());
        if ((int) ($inv['total_files'] ?? 0) !== $expected) {
            $this->markTestSkipped(sprintf(
                'fixture not provisioned (found %d files, expected %d). Run spike/orchestrator/backup-v2-e2e-setup.sh.',
                (int) ($inv['total_files'] ?? 0),
                $expected,
            ));
        }
    }
}
