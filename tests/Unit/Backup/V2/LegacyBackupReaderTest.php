<?php

declare(strict_types=1);

namespace Tests\Unit\Backup\V2;

use App\Backup\V2\Legacy\LegacyBackupReader;
use App\Services\Backup\BackupManifestV3;
use App\Services\Backup\BackupSidecarMetadata;
use Tests\TestCase;

class LegacyBackupReaderTest extends TestCase
{
    public function test_reads_multipart_v3_manifest_round_trip(): void
    {
        $files = [
            ['name' => 'database.sql.gz', 'size' => 1024, 'sha256' => str_repeat('a', 64)],
            ['name' => 'chunks/0.zip', 'size' => 2048, 'sha256' => str_repeat('b', 64)],
        ];

        $manifest = BackupManifestV3::build(
            siteId: 42,
            siteUrl: 'https://example.test',
            siteDomain: 'example.test',
            siteName: 'Example',
            type: 'full',
            trigger: 'manual',
            wpVersion: '6.6',
            phpVersion: '8.3',
            parentBackupId: null,
            files: $files,
        );
        $json = BackupManifestV3::encode($manifest);

        $reader = new LegacyBackupReader;
        $out = $reader->readManifest($json);

        $this->assertSame(LegacyBackupReader::FORMAT_MULTIPART_V3, $out['format']);
        $this->assertSame(3, $out['format_version']);
        $this->assertSame(42, $out['site_id']);
        $this->assertSame('example.test', $out['site_domain']);
        $this->assertSame('full', $out['type']);
        $this->assertSame('6.6', $out['wp_version']);
        $this->assertTrue($out['includes_files']);
        $this->assertTrue($out['includes_database']);
        $this->assertSame(2, $out['file_count']);
        $this->assertSame(1024 + 2048, $out['total_size']);
        $this->assertSame($manifest['composite_checksum'], $out['composite_checksum']);
    }

    public function test_read_dispatches_by_classified_format(): void
    {
        $reader = new LegacyBackupReader;

        $this->assertSame(
            LegacyBackupReader::FORMAT_MULTIPART_V3,
            $reader->classifyPath('site.test/full-2026/manifest.json')
        );
        $this->assertSame(
            LegacyBackupReader::FORMAT_V2_ZIP,
            $reader->classifyPath('site.test/backup.zip.meta.json')
        );
        $this->assertNull($reader->classifyPath('site.test/chunks/0.zip'));
    }

    public function test_reads_v2_zip_sidecar_round_trip(): void
    {
        $sidecar = [
            'schema_version' => BackupSidecarMetadata::SCHEMA_VERSION,
            'format' => 'v2-zip',
            'site_id' => 7,
            'site_url' => 'https://legacy.test',
            'site_domain' => 'legacy.test',
            'site_name' => 'Legacy',
            'type' => 'full',
            'trigger' => 'scheduled',
            'created_at' => '2026-01-01T00:00:00+00:00',
            'completed_at' => '2026-01-01T00:10:00+00:00',
            'file_name' => 'legacy-full-2026.zip',
            'file_size' => 999,
            'checksum' => str_repeat('c', 64),
            'includes_files' => true,
            'includes_database' => true,
            'wp_version' => '6.4',
            'php_version' => '8.2',
            'parent_backup_id' => null,
        ];
        $json = (string) json_encode($sidecar);

        $reader = new LegacyBackupReader;
        $out = $reader->readSidecar($json);

        $this->assertSame(LegacyBackupReader::FORMAT_V2_ZIP, $out['format']);
        $this->assertSame(7, $out['site_id']);
        $this->assertSame('legacy.test', $out['site_domain']);
        $this->assertSame('full', $out['type']);
        $this->assertSame('legacy-full-2026.zip', $out['file_name']);
        $this->assertSame(999, $out['file_size']);
        $this->assertSame('6.4', $out['wp_version']);

        // Same via the dispatch helper.
        $viaDispatch = $reader->read(LegacyBackupReader::FORMAT_V2_ZIP, $json);
        $this->assertSame($out, $viaDispatch);
    }

    public function test_invalid_manifest_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new LegacyBackupReader)->readManifest('{"not":"a manifest"}');
    }

    public function test_invalid_sidecar_throws(): void
    {
        $this->expectException(\RuntimeException::class);
        (new LegacyBackupReader)->readSidecar('{"schema_version": 999}');
    }
}
