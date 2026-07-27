<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\ProvenRestore;

use App\Backup\V2\Chain\ChainResolver;
use App\Backup\V2\Chain\S3ManifestReader;
use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Orchestration\BackupRunner;
use App\Backup\V2\ProvenRestore\ProvenRestoreService;
use App\Backup\V2\Restore\RestoreRunner;
use App\Backup\V2\Storage\ObjectLayout;
use App\Models\Backup;
use App\Models\ProvenRestore;
use App\Models\Site;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\V2\Storage\InteractsWithLabMinio;
use Tests\Feature\Backup\V2\Support\InProcessPluginClient;
use Tests\Feature\Backup\V2\Support\InProcessRestoreClient;
use Tests\TestCase;

/**
 * P6 PROVEN RESTORE — the proof that closes the "proven_restores empty (0)" defect.
 *
 * A real files-only V2 backup is taken (BackupRunner → MinIO, auto create-verified),
 * then ProvenRestoreService restores it into an ISOLATED sandbox filesystem (a second
 * root, never the live one) through the ACTUAL plugin restore engine, health-checks the
 * sandbox, and writes a real `proven_restores` row:
 *   - a good backup → a PASSED row (with per-check evidence),
 *   - a corrupt backup object → a FAILED row (the site/sandbox never left half-restored).
 */
#[Group('minio')]
#[Group('e2e')]
#[Group('restore')]
class ProvenRestoreServiceTest extends TestCase
{
    use InteractsWithLabMinio;
    use RefreshDatabase;

    private const THRESHOLD = 8192;

    private const SUB = 'content';

    private string $liveRoot;

    private string $sandboxRoot;

    private InProcessPluginClient $backupClient;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['backup_v2.multipart_part_mb' => 5]);
        $this->bootMinio();

        $this->liveRoot = sys_get_temp_dir().'/sam_proven_live_'.uniqid('', true);
        $this->sandboxRoot = sys_get_temp_dir().'/sam_proven_sandbox_'.uniqid('', true);
        @mkdir($this->liveRoot, 0755, true);
        @mkdir($this->sandboxRoot, 0755, true);

        $this->backupClient = new InProcessPluginClient($this->liveRoot, self::THRESHOLD);
        $this->site = Site::factory()->create();
    }

    protected function tearDown(): void
    {
        $this->backupClient->cleanup();
        $this->rrmdir($this->liveRoot);
        $this->rrmdir($this->sandboxRoot);
        $this->cleanupMinio();
        parent::tearDown();
    }

    public function test_proven_restore_of_a_good_backup_writes_a_passed_row(): void
    {
        $this->seedFiles([
            self::SUB.'/a.txt' => 'ALPHA',
            self::SUB.'/b.txt' => 'BRAVO',
            self::SUB.'/deep/c.txt' => 'CHARLIE',
        ]);
        $backup = $this->backup('full');
        $expected = $this->backupClient->liveState(); // the exact bytes the backup captured

        // Resolve the latest valid backup implicitly (null) to exercise selection too.
        $restorer = $this->restorer($this->sandboxRoot, $expected);
        $proven = (new ProvenRestoreService)->run($this->site, null, $restorer);

        $this->assertSame(ProvenRestore::STATUS_PASSED, $proven->status, 'a good backup must prove-restore passed');
        $this->assertSame($backup->id, $proven->checks['backup_session_id']);
        $this->assertSame('completed', $proven->checks['restore_state']);
        $this->assertTrue((bool) $proven->checks['health_ok']);
        $this->assertSame(count($expected), (int) $proven->checks['files_present']);

        // The defect-closing assertion: a real PASSED row now exists.
        $this->assertDatabaseHas('proven_restores', [
            'id' => $proven->id,
            'site_id' => $this->site->id,
            'status' => 'passed',
        ]);
        $this->assertSame(1, ProvenRestore::where('status', 'passed')->count());

        // The sandbox holds the restored content; the LIVE root was never the restore target.
        $this->assertSame(hash('sha256', 'ALPHA'), (string) hash_file('sha256', $this->sandboxRoot.'/'.self::SUB.'/a.txt'));
    }

    public function test_proven_restore_of_a_corrupt_backup_writes_a_failed_row(): void
    {
        $this->seedFiles([
            self::SUB.'/a.txt' => 'ALPHA',
            self::SUB.'/b.txt' => 'BRAVO',
        ]);
        $backup = $this->backup('full');
        $expected = $this->backupClient->liveState();

        // Corrupt a stored file-chunk object (same-ish bytes → a broken archive on apply).
        $manifest = $this->downloadJson($this->layoutFor($backup)->manifest());
        $fileObject = collect($manifest['objects'])->firstWhere('kind', 'files');
        $this->assertNotNull($fileObject, 'backup must have a file chunk to corrupt');
        $this->minioClient()->putObject([
            'Bucket' => $this->bucket,
            'Key' => (string) $fileObject['key'],
            'Body' => random_bytes((int) $fileObject['size']),
        ]);

        $restorer = $this->restorer($this->sandboxRoot, $expected);
        $proven = (new ProvenRestoreService)->run($this->site, $backup, $restorer);

        $this->assertSame(ProvenRestore::STATUS_FAILED, $proven->status, 'a corrupt backup must prove-restore failed');
        $this->assertDatabaseHas('proven_restores', [
            'id' => $proven->id,
            'site_id' => $this->site->id,
            'status' => 'failed',
        ]);
        $this->assertSame(0, ProvenRestore::where('status', 'passed')->count());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * A restorer closure: drive a RestoreRunner into the SANDBOX root through the
     * real plugin restore engine, then health-check the sandbox against $expected.
     *
     * @param  array<string,string>  $expected  rel-path => sha256 the sandbox must end up with
     */
    private function restorer(string $sandboxRoot, array $expected): \Closure
    {
        return function (RestoreSession $restore, BackupSession $backup) use ($sandboxRoot, $expected): array {
            $restoreClient = new InProcessRestoreClient($sandboxRoot);
            try {
                (new RestoreRunner(
                    session: $restore,
                    client: $restoreClient,
                    s3: $this->s3(),
                    bucket: $this->bucket,
                    resolver: new ChainResolver,
                    reader: $this->reader(),
                    layoutFor: fn (BackupSession $b) => $this->layoutFor($b),
                    preRestoreBackup: fn (RestoreSession $s): ?int => Backup::factory()->create(['site_id' => $this->site->id])->id,
                    healthCheck: fn (): bool => true,
                    logger: null,
                ))->run();
            } finally {
                $restoreClient->cleanup();
            }

            $restore->refresh();

            // Health-check the SANDBOX: every expected file present with matching content.
            $missing = [];
            $mismatch = [];
            foreach ($expected as $rel => $sha) {
                $abs = $sandboxRoot.'/'.$rel;
                if (! is_file($abs)) {
                    $missing[] = $rel;

                    continue;
                }
                if (! hash_equals($sha, (string) hash_file('sha256', $abs))) {
                    $mismatch[] = $rel;
                }
            }

            $present = count($expected) - count($missing);
            $ok = $restore->state === \App\Backup\V2\Enums\RestoreSessionState::Completed
                && $missing === []
                && $mismatch === [];

            return [
                'ok' => $ok,
                'checks' => [
                    'files_expected' => count($expected),
                    'files_present' => $present,
                    'missing' => $missing,
                    'mismatch' => $mismatch,
                    'db_checked' => false, // files-only sandbox in the lab; DB half proven over HTTP
                ],
                'error' => $ok ? null : 'sandbox health-check failed',
            ];
        };
    }

    /**
     * @param  array<string,string>  $files
     */
    private function seedFiles(array $files): void
    {
        foreach ($files as $rel => $content) {
            $this->backupClient->writeFile($rel, $content);
        }
    }

    private function backup(string $type): BackupSession
    {
        $session = BackupSession::create([
            'site_id' => $this->site->id,
            'type' => $type,
            'scope' => ['database' => false, 'files' => true, 'rules' => [], 'exclude_tables' => []],
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Requested,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'checkpoint' => [],
            'idempotency_key' => 'proven-bk-'.uniqid('', true),
            'format_version' => 'simplead-backup/1',
        ]);

        (new BackupRunner(
            session: $session,
            client: $this->backupClient,
            s3: $this->s3(),
            bucket: $this->bucket,
            layout: $this->layoutFor($session),
            logger: null,
            fileChunkThreshold: self::THRESHOLD,
            dbSegmentBytes: null,
            compression: 'store',
        ))->run();

        $this->assertSame(BackupSessionState::Completed, $session->refresh()->state, 'backup must complete');
        $this->assertNotNull($session->verified_at, 'backup must be create-verified at completion');

        return $session;
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

    private function reader(): S3ManifestReader
    {
        return new S3ManifestReader($this->s3(), $this->bucket, fn (BackupSession $s) => $this->layoutFor($s));
    }

    private function s3(): S3Client
    {
        return $this->minioClient();
    }

    /**
     * @return array<string,mixed>
     */
    private function downloadJson(string $key): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'pjson_');
        $this->minioClient()->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $tmp]);
        $decoded = json_decode((string) file_get_contents($tmp), true);
        @unlink($tmp);

        return is_array($decoded) ? $decoded : [];
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
