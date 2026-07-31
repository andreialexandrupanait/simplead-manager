<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Chain\ChainResolver;
use App\Backup\V2\Chain\S3ManifestReader;
use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Orchestration\BackupRunner;
use App\Backup\V2\Plugin\SimpleadBackupClient;
use App\Backup\V2\Storage\ObjectLayout;
use App\Models\Site;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Backup\V2\Storage\InteractsWithLabMinio;
use Tests\Feature\Backup\V2\Support\LabBackupFixture;
use Tests\TestCase;

/**
 * P3 real-HTTP proof against the live simplead-backup plugin (spike-wp): the files/inventory
 * endpoint accepts a base_manifest and, when the fixture is UNCHANGED between a full and its
 * incremental, plans ZERO file chunks — every unchanged file is skipped, nothing is re-uploaded —
 * while the incremental STILL takes a full DB dump (the architecture's DB strategy: full dump every
 * backup, never an incremental DB).
 *
 * The mutation-driven changed/new/tombstone diff and multi-increment chain restore are proven
 * against real object storage in IncrementalChainE2ETest (the lab cannot mutate spike-wp's
 * filesystem from inside the test container); here we prove the real endpoint wiring + unchanged
 * skip + full-DB-per-incremental over genuine HTTP.
 */
#[Group('minio')]
#[Group('e2e')]
class IncrementalHttpE2ETest extends TestCase
{
    use InteractsWithLabMinio;
    use RefreshDatabase;

    private static function wpHost(): string
    {
        // Default is the sam_spike_net container alias; override to the host-published
        // port (http://127.0.0.1:18080) when the runner is on the host network.
        return getenv('BACKUP_ENGINE_V2_LAB_WP_HOST') ?: 'http://sam_spike-spike-wp-1';
    }

    private const FILE_CHUNK_THRESHOLD = 524288;

    private const DB_SEGMENT_BYTES = 2097152;

    protected function setUp(): void
    {
        parent::setUp();
        config(['backup_v2.multipart_part_mb' => 5]);
        $this->bootMinio();
        $this->requireLabPluginAndFixture();
    }

    protected function tearDown(): void
    {
        $this->cleanupMinio();
        parent::tearDown();
    }

    public function test_unchanged_incremental_uploads_no_file_chunks_but_full_db_dump(): void
    {
        // ── FULL backup (db + files) ───────────────────────────────────────
        $full = $this->makeSession('full', null, null);
        (new BackupRunner(
            session: $full,
            client: $this->realClient(),
            s3: $this->s3(),
            bucket: $this->bucket,
            layout: $this->layoutFor($full),
            fileChunkThreshold: self::FILE_CHUNK_THRESHOLD,
            dbSegmentBytes: self::DB_SEGMENT_BYTES,
        ))->run();

        $this->assertSame(BackupSessionState::Completed, $full->refresh()->state);
        $fullManifest = $this->manifest($full);
        $this->assertGreaterThan(1, $this->countObjects($fullManifest, 'files'), 'full must span several file chunks');
        $this->assertGreaterThan(0, $this->countObjects($fullManifest, 'database'), 'full must include DB segments');

        // ── INCREMENTAL over the UNCHANGED fixture ─────────────────────────
        $resolver = new ChainResolver;
        $reader = new S3ManifestReader($this->s3(), $this->bucket, fn (BackupSession $s) => $this->layoutFor($s));

        $inc = $this->makeSession('incremental', $full->id, 1);
        (new BackupRunner(
            session: $inc,
            client: $this->realClient(),
            s3: $this->s3(),
            bucket: $this->bucket,
            layout: $this->layoutFor($inc),
            fileChunkThreshold: self::FILE_CHUNK_THRESHOLD,
            dbSegmentBytes: self::DB_SEGMENT_BYTES,
            baseManifestProvider: fn (BackupSession $s): ?array => $resolver->baseFileState($s, $reader),
        ))->run();

        $this->assertSame(BackupSessionState::Completed, $inc->refresh()->state);
        $incManifest = $this->manifest($inc);

        // (a) incremental mode + chain fields.
        $this->assertSame('incremental', $incManifest['backup_type']);
        $this->assertSame($full->id, $incManifest['full_base_id']);
        $this->assertSame(1, $incManifest['chain_position']);

        // (b) NOTHING file-side was uploaded: 0 file objects, 0 included files, 0 tombstones —
        // every unchanged file was skipped by the real plugin diff.
        $this->assertSame(0, $this->countObjects($incManifest, 'files'), 'unchanged incremental must upload no file chunks');
        $this->assertSame([], (array) ($incManifest['files']['included'] ?? []), 'no changed/new files');
        $this->assertSame([], (array) ($incManifest['files']['tombstones'] ?? []), 'no deletions → no tombstones');

        // (c) DB was STILL dumped in full on the incremental (full-dump-every-backup).
        $this->assertGreaterThan(0, $this->countObjects($incManifest, 'database'), 'incremental must still take a full DB dump');

        // (d) the diff summary recorded that the whole base was seen as unchanged.
        $diff = $inc->refresh()->checkpoint['incremental']['diff'] ?? null;
        $this->assertIsArray($diff);
        $this->assertSame(0, (int) $diff['changed_count']);
        $this->assertSame(0, (int) $diff['new_count']);
        $this->assertGreaterThan(0, (int) $diff['unchanged_count'], 'the base files were seen and skipped as unchanged');
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function makeSession(string $type, ?int $baseId, ?int $position): BackupSession
    {
        $site = Site::factory()->create();

        return BackupSession::create([
            'site_id' => $site->id,
            'type' => $type,
            'scope' => [
                'database' => true,
                'files' => true,
                'rules' => LabBackupFixture::includeRule(),
                'exclude_tables' => [],
            ],
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Requested,
            'full_base_id' => $baseId,
            'chain_position' => $position,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'checkpoint' => [],
            'idempotency_key' => 'inc-http-'.uniqid('', true),
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

    private function s3(): S3Client
    {
        return $this->minioClient();
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(BackupSession $session): array
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'incht_');
        $this->s3()->getObject(['Bucket' => $this->bucket, 'Key' => $this->layoutFor($session)->manifest(), 'SaveAs' => $tmp]);
        $decoded = json_decode((string) file_get_contents($tmp), true);
        @unlink($tmp);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function countObjects(array $manifest, string $kind): int
    {
        return count(array_filter(
            (array) ($manifest['objects'] ?? []),
            static fn (array $o): bool => ($o['kind'] ?? null) === $kind,
        ));
    }

    private function requireLabPluginAndFixture(): void
    {
        $client = $this->realClient();

        try {
            $caps = $client->capabilities();
        } catch (\Throwable $e) {
            $this->labUnavailable('simplead-backup plugin not reachable at '.self::wpHost().': '.$e->getMessage());
        }

        if (($caps['plugin']['name'] ?? null) !== 'simplead-backup') {
            $this->labUnavailable('simplead-backup plugin not active on '.self::wpHost());
        }

        try {
            $inv = $client->filesInventory('preflight_'.uniqid('', true), LabBackupFixture::includeRule(), ['preview' => true]);
        } catch (\Throwable $e) {
            $this->labUnavailable('files inventory preflight failed: '.$e->getMessage());
        }

        if ((int) ($inv['total_files'] ?? 0) !== count(LabBackupFixture::spec())) {
            $this->labUnavailable('fixture not provisioned. Run spike/orchestrator/backup-v2-e2e-setup.sh.');
        }
    }
}
