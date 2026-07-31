<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Chain\BrokenChainException;
use App\Backup\V2\Chain\ChainResolver;
use App\Backup\V2\Chain\S3ManifestReader;
use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Orchestration\BackupRunner;
use App\Backup\V2\Storage\ObjectLayout;
use App\Models\Site;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\V2\Storage\InteractsWithLabMinio;
use Tests\Feature\Backup\V2\Support\InProcessPluginClient;
use Tests\TestCase;
use ZipArchive;

/**
 * P3 incremental + chain E2E against REAL MinIO, driving the ACTUAL simplead-backup file diff +
 * chunker classes (in-process, CLI-loaded) over a controllable fixture that is mutated between
 * backups.
 *
 * Proves the P3 gate end-to-end:
 *   1. Incremental uploads ONLY changed+new files (unchanged skipped), records tombstones for
 *      deletions, and stamps full_base_id + chain_position.
 *   2. Restore of full + inc1 + inc2 materialises the chain (ChainResolver) and reproduces the live
 *      fixture BIT-FOR-BIT (0 missing / 0 mismatch), with tombstoned (deleted) files absent.
 *   3. A broken chain (base removed) is refused with BrokenChainException before any restore.
 *
 * DB is out of scope here (files-only sessions); full-DB-per-backup is proven by the HTTP E2E.
 */
#[Group('minio')]
#[Group('e2e')]
class IncrementalChainE2ETest extends TestCase
{
    use InteractsWithLabMinio;
    use RefreshDatabase;

    private const THRESHOLD = 8192; // small → several directory-local chunks

    private InProcessPluginClient $client;

    private string $fixtureRoot;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['backup_v2.multipart_part_mb' => 5]);
        $this->bootMinio();

        $this->fixtureRoot = sys_get_temp_dir().'/sam_inc_fixture_'.uniqid('', true);
        $this->client = new InProcessPluginClient($this->fixtureRoot, self::THRESHOLD);
        $this->site = Site::factory()->create();
    }

    protected function tearDown(): void
    {
        // setUp() can markTestSkipped mid-way (lab MinIO absent) before $client
        // is assigned; guard so tearDown skips cleanly.
        if (isset($this->client)) {
            $this->client->cleanup();
        }
        $this->cleanupMinio();
        parent::tearDown();
    }

    public function test_full_plus_two_increments_restores_bit_identical_with_tombstones(): void
    {
        // ── initial fixture → FULL backup ──────────────────────────────────
        $this->client->writeFile('dir1/f1.dat', str_repeat('a', 100));
        $this->client->writeFile('dir1/f2.dat', str_repeat('b', 3000));
        $this->client->writeFile('dir1/f3.dat', str_repeat('c', 5000));
        $this->client->writeFile('dir2/g1.dat', str_repeat('d', 2000));
        $this->client->writeFile('dir2/g2.dat', str_repeat('e', 900));
        $this->client->writeFile('dir2/g3.dat', str_repeat('f', 1500));

        $full = $this->makeSession('full', null, null);
        $this->runBackup($full);
        $this->assertSame(BackupSessionState::Completed, $full->refresh()->state);

        // ── mutate → INCREMENTAL 1 ─────────────────────────────────────────
        $this->client->writeFile('dir1/f1.dat', str_repeat('A', 250)); // changed (size differs)
        $this->client->writeFile('dir1/f6.dat', str_repeat('g', 700));  // new
        $this->client->deleteFile('dir2/g3.dat');                       // deleted → tombstone

        $inc1 = $this->makeSession('incremental', $full->id, 1);
        $this->runBackup($inc1);
        $this->assertSame(BackupSessionState::Completed, $inc1->refresh()->state);

        // (1) incremental correctness: ONLY changed+new uploaded, unchanged NOT re-uploaded.
        $m1 = $this->manifest($inc1);
        $included1 = $this->includedPaths($m1);
        sort($included1);
        $this->assertSame(['dir1/f1.dat', 'dir1/f6.dat'], $included1, 'incremental uploads only changed+new');
        $this->assertNotContains('dir1/f2.dat', $included1, 'unchanged file must not be re-uploaded');
        $this->assertNotContains('dir2/g1.dat', $included1, 'unchanged file must not be re-uploaded');
        $this->assertSame(['dir2/g3.dat'], $m1['files']['tombstones'], 'deleted file recorded as tombstone');
        $this->assertSame($full->id, $m1['full_base_id']);
        $this->assertSame(1, $m1['chain_position']);
        $this->assertSame($full->id, $m1['base_manifest_ref']);

        // ── mutate → INCREMENTAL 2 ─────────────────────────────────────────
        $this->client->writeFile('dir2/g1.dat', str_repeat('D', 4200)); // changed
        $this->client->writeFile('dir3/h1.dat', str_repeat('h', 1200));  // new
        $this->client->deleteFile('dir1/f2.dat');                        // deleted → tombstone

        $inc2 = $this->makeSession('incremental', $full->id, 2);
        $this->runBackup($inc2);
        $this->assertSame(BackupSessionState::Completed, $inc2->refresh()->state);

        $m2 = $this->manifest($inc2);
        $included2 = $this->includedPaths($m2);
        sort($included2);
        $this->assertSame(['dir2/g1.dat', 'dir3/h1.dat'], $included2, 'inc2 uploads only its changed+new');
        $this->assertSame(['dir1/f2.dat'], $m2['files']['tombstones']);
        $this->assertSame(2, $m2['chain_position']);

        // (2) RESTORE FROM CHAIN — materialise full+inc1+inc2, reproduce live fixture bit-for-bit.
        $resolver = new ChainResolver;
        $reader = $this->manifestReader();

        $chain = $resolver->resolveChain($inc2);
        $this->assertSame([$full->id, $inc1->id, $inc2->id], array_map(fn ($s) => $s->id, $chain));

        $state = $resolver->materialize($chain, $reader);
        $live = $this->client->liveState();

        // Same set of paths → tombstoned files are absent, nothing missing.
        $this->assertEqualsCanonicalizing(array_keys($live), array_keys($state), 'chain state must equal the live fixture set');

        // Deleted files must NOT reappear.
        $this->assertArrayNotHasKey('dir2/g3.dat', $state, 'inc1 tombstone applied');
        $this->assertArrayNotHasKey('dir1/f2.dat', $state, 'inc2 tombstone applied');

        // Bit-identical: extract each file from its source chunk in MinIO and compare sha256.
        $missing = [];
        $mismatched = [];
        foreach ($live as $path => $liveSha) {
            if (! isset($state[$path])) {
                $missing[] = $path;

                continue;
            }
            $bytes = $this->extractFromChunk((string) $state[$path]['key'], $path);
            if ($bytes === null || ! hash_equals($liveSha, hash('sha256', $bytes))) {
                $mismatched[] = $path;
            }
        }
        $this->assertSame([], $missing, 'restore-from-chain: files missing');
        $this->assertSame([], $mismatched, 'restore-from-chain: files with wrong content');

        // Latest-wins provenance: dir1/f1 came from inc1, dir2/g1 from inc2, dir1/f3 still from full.
        $this->assertSame($inc1->id, $state['dir1/f1.dat']['source_session_id']);
        $this->assertSame($inc2->id, $state['dir2/g1.dat']['source_session_id']);
        $this->assertSame($full->id, $state['dir1/f3.dat']['source_session_id']);

        // (3a) broken chain: base full no longer valid (incomplete) → resolveChain refuses.
        BackupSession::where('id', $full->id)->update(['state' => BackupSessionState::Failed->value]);
        try {
            (new ChainResolver)->resolveChain($inc2->fresh());
            $this->fail('expected BrokenChainException for an incomplete base full');
        } catch (BrokenChainException $e) {
            $this->assertStringContainsString('missing or not completed', $e->getMessage());
        }

        // (3b) corrupt base: base manifest object gone → materialise refuses before restoring.
        BackupSession::where('id', $full->id)->update(['state' => BackupSessionState::Completed->value]);
        $this->s3()->deleteObject(['Bucket' => $this->bucket, 'Key' => $this->layoutFor($full)->manifest()]);
        $this->expectException(BrokenChainException::class);
        (new ChainResolver)->materialize((new ChainResolver)->resolveChain($inc2->fresh()), $this->manifestReader());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeSession(string $type, ?int $baseId, ?int $position): BackupSession
    {
        return BackupSession::create([
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
            'idempotency_key' => 'inc-e2e-'.uniqid('', true),
            'format_version' => 'simplead-backup/1',
        ]);
    }

    private function runBackup(BackupSession $session): void
    {
        $resolver = new ChainResolver;
        $reader = $this->manifestReader();
        $baseProvider = fn (BackupSession $s): ?array => $s->type === 'incremental'
            ? $resolver->baseFileState($s, $reader)
            : null;

        (new BackupRunner(
            session: $session,
            client: $this->client,
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

    private function manifestReader(): S3ManifestReader
    {
        return new S3ManifestReader($this->s3(), $this->bucket, fn (BackupSession $s) => $this->layoutFor($s));
    }

    private function s3(): S3Client
    {
        return $this->minioClient();
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(BackupSession $session): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'incm_');
        $this->s3()->getObject(['Bucket' => $this->bucket, 'Key' => $this->layoutFor($session)->manifest(), 'SaveAs' => $tmp]);
        $decoded = json_decode((string) file_get_contents($tmp), true);
        @unlink($tmp);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    private function includedPaths(array $manifest): array
    {
        return array_values(array_map(
            static fn (array $e): string => (string) $e['p'],
            (array) ($manifest['files']['included'] ?? []),
        ));
    }

    /**
     * Objects are sealed before they reach the bucket, so what is stored is
     * deliberately not a readable zip — asserting that it is would be asserting
     * that encryption had failed. Decrypting first makes this stronger: it
     * proves the bytes survive the whole round trip through real storage and
     * the cipher, across every link of the chain.
     */
    private function extractFromChunk(string $key, string $entry): ?string
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'incz_');
        $this->s3()->getObject(['Bucket' => $this->bucket, 'Key' => $key, 'SaveAs' => $tmp]);

        if (\App\Backup\V2\Crypto\ObjectCipher::isEncrypted($tmp)) {
            $plain = (string) tempnam(sys_get_temp_dir(), 'incplain_');
            (new \App\Backup\V2\Crypto\BackupKeyring)
                ->forKeyId((string) \App\Backup\V2\Crypto\ObjectCipher::keyIdOf($tmp))
                ->decryptFile($tmp, $plain);
            @unlink($tmp);
            $tmp = $plain;
        }
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CHECKCONS) !== true) {
            @unlink($tmp);

            return null;
        }
        $bytes = $zip->getFromName($entry);
        $zip->close();
        @unlink($tmp);

        return $bytes === false ? null : $bytes;
    }
}
