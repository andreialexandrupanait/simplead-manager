<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Restore;

use App\Backup\V2\Chain\ChainResolver;
use App\Backup\V2\Chain\S3ManifestReader;
use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Enums\RestoreSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Orchestration\BackupRunner;
use App\Backup\V2\Restore\RestoreRunner;
use App\Backup\V2\Storage\ObjectLayout;
use App\Models\Backup;
use App\Models\Site;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\V2\Storage\InteractsWithLabMinio;
use Tests\Feature\Backup\V2\Support\InProcessPluginClient;
use Tests\Feature\Backup\V2\Support\InProcessRestoreClient;
use Tests\TestCase;

/**
 * End-to-end proof of the RESTORE FSM (RestoreRunner) against REAL MinIO: back a fixture up with the
 * real BackupRunner, mutate the live tree, then drive a RestoreSession through the whole FSM
 * (validating_backup → … → post_restore_validation → completed) — pulling the real chunk objects
 * from MinIO and applying them through the ACTUAL plugin restore engine (staged, journaled, atomic).
 *
 * Proves: full round-trip (MIRROR vs SAFE_MERGE), restore-from-chain (latest-wins + tombstones),
 * selective folder scope, and validation-failure → rollback (site returned to pre-apply).
 * The DB half needs mysqli (absent in lab-php) and is proven over HTTP against spike-wp.
 */
#[Group('minio')]
#[Group('e2e')]
#[Group('restore')]
class RestoreRunnerE2ETest extends TestCase
{
    use InteractsWithLabMinio;
    use RefreshDatabase;

    private const THRESHOLD = 8192;

    private const SUB = 'content';

    private InProcessPluginClient $backupClient;

    private InProcessRestoreClient $restoreClient;

    private string $liveRoot;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['backup_v2.multipart_part_mb' => 5]);
        $this->bootMinio();

        $this->liveRoot = sys_get_temp_dir().'/sam_restore_e2e_'.uniqid('', true);
        @mkdir($this->liveRoot, 0755, true);
        $this->backupClient = new InProcessPluginClient($this->liveRoot, self::THRESHOLD);
        $this->restoreClient = new InProcessRestoreClient($this->liveRoot);
        $this->site = Site::factory()->create();
    }

    protected function tearDown(): void
    {
        // setUp() can markTestSkipped mid-way (lab MinIO absent) before these
        // typed properties are assigned; guard so tearDown skips cleanly instead
        // of erroring on an uninitialized property.
        if (isset($this->backupClient)) {
            $this->backupClient->cleanup();
        }
        if (isset($this->restoreClient)) {
            $this->restoreClient->cleanup();
        }
        if (isset($this->liveRoot)) {
            $this->rrmdir($this->liveRoot);
        }
        $this->cleanupMinio();
        parent::tearDown();
    }

    // ── Test 1: full round-trip — MIRROR reproduces the backup exactly ──

    public function test_full_restore_mirror_reproduces_backup_exactly(): void
    {
        $this->seedFiles([
            self::SUB.'/a.txt' => 'A1',
            self::SUB.'/b.txt' => 'B1',
            self::SUB.'/deep/c.txt' => 'C1',
        ]);
        $full = $this->backup('full');
        $backupState = $this->backupClient->liveState();

        // Mutate after the backup: change a, delete b, add live-only.
        $this->backupClient->writeFile(self::SUB.'/a.txt', 'A1-CHANGED');
        $this->backupClient->deleteFile(self::SUB.'/b.txt');
        $this->backupClient->writeFile(self::SUB.'/added.txt', 'ADDED');

        $restore = $this->restore($full, 'mirror', ['database' => false, 'files' => true, 'paths' => [self::SUB]]);

        $this->assertSame(RestoreSessionState::Completed, $restore->refresh()->state);
        // MIRROR: live == backup exactly (added.txt removed, b recreated, a reverted).
        $this->assertSame($backupState, $this->backupClient->liveState());
    }

    // ── Test 2: full round-trip — SAFE_MERGE keeps live-only additions ──

    public function test_full_restore_safe_merge_keeps_live_additions(): void
    {
        $this->seedFiles([
            self::SUB.'/a.txt' => 'A1',
            self::SUB.'/b.txt' => 'B1',
        ]);
        $full = $this->backup('full');

        $this->backupClient->writeFile(self::SUB.'/a.txt', 'A1-CHANGED');
        $this->backupClient->deleteFile(self::SUB.'/b.txt');
        $this->backupClient->writeFile(self::SUB.'/added.txt', 'ADDED');

        $restore = $this->restore($full, 'safe_merge', ['database' => false, 'files' => true, 'paths' => [self::SUB]]);
        $this->assertSame(RestoreSessionState::Completed, $restore->refresh()->state);

        $live = $this->backupClient->liveState();
        // Backup files restored…
        $this->assertSame(hash('sha256', 'A1'), $live[self::SUB.'/a.txt']);
        $this->assertArrayHasKey(self::SUB.'/b.txt', $live);
        // …and the live-only addition SURVIVES (non-destructive merge).
        $this->assertArrayHasKey(self::SUB.'/added.txt', $live);
        $this->assertSame(hash('sha256', 'ADDED'), $live[self::SUB.'/added.txt']);
    }

    // ── Test 3: restore from a chain (full + 2 incrementals) ──

    public function test_restore_from_chain_reconstructs_final_state_with_tombstones(): void
    {
        $this->seedFiles([
            self::SUB.'/f1.dat' => str_repeat('a', 100),
            self::SUB.'/f2.dat' => str_repeat('b', 200),
            self::SUB.'/f3.dat' => str_repeat('c', 300),
        ]);
        $full = $this->backup('full');

        $this->backupClient->writeFile(self::SUB.'/f1.dat', str_repeat('A', 150)); // changed
        $this->backupClient->writeFile(self::SUB.'/f4.dat', str_repeat('d', 120)); // new
        $this->backupClient->deleteFile(self::SUB.'/f2.dat');                       // tombstone
        $inc1 = $this->backup('incremental', $full->id, 1);

        $this->backupClient->writeFile(self::SUB.'/f3.dat', str_repeat('C', 400)); // changed
        $this->backupClient->deleteFile(self::SUB.'/f4.dat');                       // tombstone
        $inc2 = $this->backup('incremental', $full->id, 2);

        $chainState = $this->backupClient->liveState(); // the final chain state

        // Wreck the live site entirely, then restore the chain tip.
        $this->backupClient->writeFile(self::SUB.'/f1.dat', 'GARBAGE');
        $this->backupClient->writeFile(self::SUB.'/junk.txt', 'JUNK');
        $this->backupClient->deleteFile(self::SUB.'/f3.dat');

        $restore = $this->restore($inc2, 'mirror', ['database' => false, 'files' => true, 'paths' => [self::SUB]]);
        $this->assertSame(RestoreSessionState::Completed, $restore->refresh()->state);

        $live = $this->backupClient->liveState();
        // Exact final chain state: latest-wins content, tombstoned files absent, junk removed.
        $this->assertSame($chainState, $live);
        $this->assertArrayNotHasKey(self::SUB.'/f2.dat', $live, 'inc1 tombstone applied');
        $this->assertArrayNotHasKey(self::SUB.'/f4.dat', $live, 'inc2 tombstone applied');
        $this->assertArrayNotHasKey(self::SUB.'/junk.txt', $live, 'live junk mirrored away');
        $this->assertSame(hash('sha256', str_repeat('A', 150)), $live[self::SUB.'/f1.dat']);
        $this->assertSame(hash('sha256', str_repeat('C', 400)), $live[self::SUB.'/f3.dat']);
    }

    // ── Test 4: selective folder scope — only the scoped folder is touched ──

    public function test_selective_folder_scope_only_touches_that_folder(): void
    {
        $this->seedFiles([
            'one/x.txt' => 'ONE-X',
            'two/y.txt' => 'TWO-Y',
        ]);
        $full = $this->backup('full');

        $this->backupClient->writeFile('one/x.txt', 'ONE-X-CHANGED');
        $this->backupClient->writeFile('one/extra.txt', 'ONE-EXTRA');
        $this->backupClient->writeFile('two/y.txt', 'TWO-Y-CHANGED');
        $this->backupClient->writeFile('two/extra.txt', 'TWO-EXTRA');

        // Restore MIRROR scoped to 'one' only.
        $restore = $this->restore($full, 'mirror', ['database' => false, 'files' => true, 'paths' => ['one']]);
        $this->assertSame(RestoreSessionState::Completed, $restore->refresh()->state);

        $live = $this->backupClient->liveState();
        // 'one' restored + mirrored.
        $this->assertSame(hash('sha256', 'ONE-X'), $live['one/x.txt']);
        $this->assertArrayNotHasKey('one/extra.txt', $live);
        // 'two' completely untouched.
        $this->assertSame(hash('sha256', 'TWO-Y-CHANGED'), $live['two/y.txt']);
        $this->assertSame(hash('sha256', 'TWO-EXTRA'), $live['two/extra.txt']);
    }

    // ── Test 5: post-restore validation fails → rollback (site not broken) ──

    public function test_validation_failure_rolls_back_to_pre_apply(): void
    {
        $this->seedFiles([
            self::SUB.'/a.txt' => 'A1',
            self::SUB.'/b.txt' => 'B1',
        ]);
        $full = $this->backup('full');

        $this->backupClient->writeFile(self::SUB.'/a.txt', 'A1-CHANGED');
        $this->backupClient->writeFile(self::SUB.'/live-only.txt', 'LIVE');
        $preApply = $this->backupClient->liveState();

        // Health-check returns false → runner must roll back to pre-apply and fail.
        $restore = $this->restore(
            $full,
            'mirror',
            ['database' => false, 'files' => true, 'paths' => [self::SUB]],
            healthOk: false,
        );

        $this->assertSame(RestoreSessionState::Failed, $restore->refresh()->state);
        // Site is exactly at its pre-apply state — nothing broken by the aborted restore.
        $this->assertSame($preApply, $this->backupClient->liveState());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    /**
     * @param  array<string,string>  $files
     */
    private function seedFiles(array $files): void
    {
        foreach ($files as $rel => $content) {
            $this->backupClient->writeFile($rel, $content);
        }
    }

    private function backup(string $type, ?int $baseId = null, ?int $position = null): BackupSession
    {
        $session = BackupSession::create([
            'site_id' => $this->site->id,
            'type' => $type,
            'scope' => ['database' => false, 'files' => true, 'rules' => [], 'exclude_tables' => []],
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Requested,
            'full_base_id' => $baseId,
            'chain_position' => $position,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'checkpoint' => [],
            'idempotency_key' => 'restore-e2e-bk-'.uniqid('', true),
            'format_version' => 'simplead-backup/1',
        ]);

        $resolver = new ChainResolver;
        $reader = $this->reader();
        $baseProvider = fn (BackupSession $s): ?array => $s->type === 'incremental'
            ? $resolver->baseFileState($s, $reader)
            : null;

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
            faultAfterObject: null,
            baseManifestProvider: $baseProvider,
        ))->run();

        $this->assertSame(BackupSessionState::Completed, $session->refresh()->state, 'backup must complete');

        return $session;
    }

    /**
     * @param  array<string,mixed>  $scope
     */
    private function restore(BackupSession $target, string $mode, array $scope, bool $healthOk = true): RestoreSession
    {
        $session = RestoreSession::create([
            'site_id' => $this->site->id,
            'backup_session_id' => $target->id,
            'mode' => $mode,
            'scope' => $scope,
            'state' => RestoreSessionState::Requested,
            'checkpoint' => [],
            'idempotency_key' => 'restore-e2e-'.uniqid('', true),
        ]);

        $preRestore = fn (RestoreSession $s): ?int => Backup::factory()->create([
            'site_id' => $this->site->id,
        ])->id;

        (new RestoreRunner(
            session: $session,
            client: $this->restoreClient,
            s3: $this->s3(),
            bucket: $this->bucket,
            resolver: new ChainResolver,
            reader: $this->reader(),
            layoutFor: fn (BackupSession $b) => $this->layoutFor($b),
            preRestoreBackup: $preRestore,
            healthCheck: fn (): bool => $healthOk,
            logger: null,
        ))->run();

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
