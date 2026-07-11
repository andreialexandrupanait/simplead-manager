<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Backup;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Support\Facades\Log;
use ZipArchive;

/**
 * Validates a freshly-built backup archive before it is declared `completed`.
 *
 * Catches the failure modes that a `Backup.status = 'completed'` row alone cannot:
 * truncated archives, ZIP central directory corruption, missing/malformed metadata,
 * empty or structurally broken DB dumps, inner-chunk CRC mismatches.
 *
 * Cheap by design — runs on every backup. ~30s for a 1 GB archive.
 */
class IntegrityVerifier
{
    public function __construct(
        private readonly SqlDumpParser $sqlParser = new SqlDumpParser
    ) {}

    /**
     * @return array{ok: bool, message: string, checks: array<string, mixed>}
     */
    public function verifyArchive(string $archivePath, string $expectedSha256): array
    {
        $checks = [];

        if (! is_file($archivePath)) {
            return $this->fail('archive missing', ['archive_path' => $archivePath]);
        }

        // 1. SHA256 matches
        $actualSha = hash_file('sha256', $archivePath);
        $checks['sha256_match'] = ($actualSha === $expectedSha256);
        if (! $checks['sha256_match']) {
            return $this->fail("sha256 mismatch (expected {$expectedSha256}, got {$actualSha})", $checks);
        }

        // 2. Outer ZIP opens with consistency check
        $outer = new ZipArchive;
        $openResult = $outer->open($archivePath, ZipArchive::CHECKCONS);
        if ($openResult !== true) {
            return $this->fail("outer zip CHECKCONS failed (code {$openResult})", $checks);
        }
        $checks['outer_zip_consistent'] = true;
        $checks['outer_entry_count'] = $outer->numFiles;

        // 3. backup-meta.json present + parseable + has expected fields
        $metaJson = $outer->getFromName('backup-meta.json');
        if ($metaJson === false) {
            $outer->close();

            return $this->fail('backup-meta.json missing', $checks);
        }
        $meta = json_decode($metaJson, true);
        if (! is_array($meta)) {
            $outer->close();

            return $this->fail('backup-meta.json not parseable', $checks);
        }
        $missingFields = array_diff(['site_url', 'type', 'created_at', 'trigger'], array_keys($meta));
        if ($missingFields) {
            $outer->close();

            return $this->fail('backup-meta.json missing fields: '.implode(',', $missingFields), $checks);
        }
        $checks['meta_ok'] = true;
        $checks['format_version'] = $meta['format_version'] ?? 1;
        $expectedChunkFiles = $meta['chunk_files'] ?? null;

        // 4. DB dump validates (extract to temp, parse, then unlink)
        $dbCheck = $this->verifyDatabaseDump($outer, dirname($archivePath));
        $checks['database'] = $dbCheck;
        if (! $dbCheck['ok']) {
            $outer->close();

            return $this->fail("database dump invalid: {$dbCheck['error']}", $checks);
        }

        // 5. Files archive(s) — every chunk listed in meta must exist + open clean
        $checks['files'] = $this->verifyFilesEntries($outer, $expectedChunkFiles, dirname($archivePath));
        $outer->close();

        if (! $checks['files']['ok']) {
            return $this->fail("files entry invalid: {$checks['files']['error']}", $checks);
        }

        return [
            'ok' => true,
            'message' => sprintf(
                'integrity ok: %d tables, %d inserts, %d files entries (format v%d)',
                $checks['database']['table_count'],
                $checks['database']['insert_count'],
                $checks['files']['entry_count'],
                $checks['format_version']
            ),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{ok: bool, error?: string, table_count?: int, insert_count?: int}
     */
    private function verifyDatabaseDump(ZipArchive $outer, string $tempDirRoot): array
    {
        $entryName = 'database.sql.gz';
        if ($outer->locateName($entryName) === false) {
            return ['ok' => false, 'error' => "{$entryName} not in archive"];
        }

        $tempPath = $tempDirRoot.'/verify-db-'.uniqid().'.sql.gz';
        if ($outer->extractTo($tempDirRoot, $entryName) === false) {
            return ['ok' => false, 'error' => "failed to extract {$entryName}"];
        }
        $extractedAt = $tempDirRoot.'/'.$entryName;
        // Move to unique name so concurrent verifies don't race
        @rename($extractedAt, $tempPath);

        try {
            $result = $this->sqlParser->parse($tempPath);

            return $result['ok']
                ? ['ok' => true, 'table_count' => $result['table_count'], 'insert_count' => $result['insert_count']]
                : ['ok' => false, 'error' => $result['error'] ?? 'unknown parser failure'];
        } finally {
            @unlink($tempPath);
            @unlink($extractedAt);
        }
    }

    /**
     * @param  array<string>|null  $expectedChunkFiles
     * @return array{ok: bool, error?: string, entry_count: int}
     */
    private function verifyFilesEntries(ZipArchive $outer, ?array $expectedChunkFiles, string $tempDirRoot): array
    {
        // Collect candidate entry names
        $candidates = [];
        if ($expectedChunkFiles !== null) {
            $candidates = $expectedChunkFiles;
        } else {
            // Legacy v1 — scan for files.zip
            if ($outer->locateName('files.zip') !== false) {
                $candidates[] = 'files.zip';
            }
        }

        if ($candidates === []) {
            // db-only backup — no files entries expected, that's valid
            return ['ok' => true, 'entry_count' => 0];
        }

        foreach ($candidates as $entryName) {
            if ($outer->locateName($entryName) === false) {
                return ['ok' => false, 'error' => "{$entryName} declared in meta but not in outer zip", 'entry_count' => 0];
            }

            $tempInnerPath = $tempDirRoot.'/verify-inner-'.uniqid().'.zip';
            if ($outer->extractTo($tempDirRoot, $entryName) === false) {
                return ['ok' => false, 'error' => "failed to extract {$entryName}", 'entry_count' => 0];
            }
            $extractedAt = $tempDirRoot.'/'.$entryName;
            @rename($extractedAt, $tempInnerPath);

            try {
                $inner = new ZipArchive;
                $code = $inner->open($tempInnerPath, ZipArchive::CHECKCONS);
                if ($code !== true) {
                    return ['ok' => false, 'error' => "{$entryName} CHECKCONS failed (code {$code})", 'entry_count' => 0];
                }
                $inner->close();
            } finally {
                @unlink($tempInnerPath);
                @unlink($extractedAt);
            }
        }

        return ['ok' => true, 'entry_count' => count($candidates)];
    }

    /**
     * Verify a freshly-built v3-zip archive (Level A — runs at backup creation,
     * before declaring completed). Checks:
     *   - SHA256 of file matches expected
     *   - Outer ZIP opens with CHECKCONS (per-entry CRC validation)
     *   - database.sql.gz present + parseable via SqlDumpParser
     *   - backup-meta.json present + parseable
     *   - At least one entry under files/ (non-empty WP files area, unless DB-only)
     *
     * @return array{ok: bool, message: string, checks: array<string, mixed>}
     */
    public function verifyV3Zip(string $archivePath, string $expectedSha256): array
    {
        $checks = [];

        if (! is_file($archivePath)) {
            return $this->fail('archive missing', ['archive_path' => $archivePath]);
        }

        // 1. SHA256
        $actualSha = hash_file('sha256', $archivePath);
        $checks['sha256_match'] = ($actualSha === $expectedSha256);
        if (! $checks['sha256_match']) {
            return $this->fail("sha256 mismatch (expected {$expectedSha256}, got {$actualSha})", $checks);
        }

        // 2. Outer ZIP CHECKCONS — validates each entry's CRC at byte level
        $zip = new ZipArchive;
        $openResult = $zip->open($archivePath, ZipArchive::CHECKCONS);
        if ($openResult !== true) {
            return $this->fail("outer zip CHECKCONS failed (code {$openResult})", $checks);
        }
        $checks['outer_zip_consistent'] = true;
        $checks['entry_count'] = $zip->numFiles;

        // 3. backup-meta.json
        $metaJson = $zip->getFromName('backup-meta.json');
        if ($metaJson === false) {
            $zip->close();

            return $this->fail('backup-meta.json missing', $checks);
        }
        $meta = json_decode($metaJson, true);
        if (! is_array($meta)) {
            $zip->close();

            return $this->fail('backup-meta.json not parseable', $checks);
        }
        $checks['meta_format'] = $meta['format'] ?? null;
        $checks['meta_type'] = $meta['type'] ?? null;

        // 4. database.sql.gz — extract to temp + run SqlDumpParser
        $dbCheck = $this->verifyV3ZipDatabase($zip, dirname($archivePath));
        $checks['database'] = $dbCheck;
        if (! $dbCheck['ok']) {
            $zip->close();

            return $this->fail("database dump invalid: {$dbCheck['error']}", $checks);
        }

        // 5. files/ subtree — at least one entry expected for non-DB-only backups.
        //
        // A zero-change incremental legitimately has NO files/* entries: the only
        // thing that changed that day is the database, so the delta is DB-only
        // (P0-04). We must accept it as valid — requiring files here marked every
        // quiet-site daily incremental as failed. We still REQUIRE files when the
        // incremental reports changed files (files_changed_count > 0), so a
        // truncated archive that dropped its files is still caught.
        $type = $meta['type'] ?? 'full';
        $filesChangedCount = $meta['files_changed_count'] ?? null;
        $isZeroChangeIncremental = ($type === 'incremental')
            && $filesChangedCount !== null
            && (int) $filesChangedCount === 0;

        if ($type !== 'database' && ! $isZeroChangeIncremental) {
            $hasFiles = false;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name && str_starts_with($name, 'files/') && ! str_ends_with($name, '/')) {
                    $hasFiles = true;
                    break;
                }
            }
            if (! $hasFiles) {
                $zip->close();

                return $this->fail("no files/* entries in v3-zip (expected for type={$type})", $checks);
            }
            $checks['has_files'] = true;
        } elseif ($isZeroChangeIncremental) {
            $checks['has_files'] = false;
            $checks['zero_change_incremental'] = true;
        }

        $zip->close();

        return [
            'ok' => true,
            'message' => sprintf(
                'v3-zip integrity ok: %d entries, %d tables, %d inserts',
                $checks['entry_count'],
                $checks['database']['table_count'] ?? 0,
                $checks['database']['insert_count'] ?? 0
            ),
            'checks' => $checks,
        ];
    }

    /**
     * @return array{ok: bool, error?: string, table_count?: int, insert_count?: int}
     */
    private function verifyV3ZipDatabase(ZipArchive $zip, string $tempDirRoot): array
    {
        if ($zip->locateName('database.sql.gz') === false) {
            return ['ok' => false, 'error' => 'database.sql.gz not in archive'];
        }

        $tempPath = $tempDirRoot.'/verify-db-'.uniqid().'.sql.gz';
        if ($zip->extractTo($tempDirRoot, 'database.sql.gz') === false) {
            return ['ok' => false, 'error' => 'failed to extract database.sql.gz'];
        }
        $extractedAt = $tempDirRoot.'/database.sql.gz';
        @rename($extractedAt, $tempPath);

        try {
            $result = $this->sqlParser->parse($tempPath);

            return $result['ok']
                ? ['ok' => true, 'table_count' => $result['table_count'], 'insert_count' => $result['insert_count']]
                : ['ok' => false, 'error' => $result['error'] ?? 'unknown parser failure'];
        } finally {
            @unlink($tempPath);
            @unlink($extractedAt);
        }
    }

    /**
     * Level B verification for a multipart-v3 backup. Downloads each file,
     * verifies sha256 against the manifest, and recomputes the composite
     * checksum stored on Backup.checksum.
     *
     * @return array{ok: bool, message: string, checks: array<string, mixed>}
     */
    public function verifyMultipart(Backup $backup, string $tempDir): array
    {
        if ($backup->format !== BackupManifestV3::FORMAT) {
            return $this->fail('verifyMultipart called on non-multipart backup', ['format' => $backup->format]);
        }

        $candidates = [];
        if ($backup->storage_destination_id) {
            $candidates[] = $backup->storage_destination_id;
        }
        foreach ($backup->replicas ?? [] as $r) {
            if (! empty($r['destination_id'])) {
                $candidates[] = (int) $r['destination_id'];
            }
        }
        $candidates = array_unique($candidates);

        if (! $candidates) {
            return $this->fail('no replica destinations to verify against', []);
        }

        // Pick first reachable destination
        $destination = null;
        foreach ($candidates as $destId) {
            $d = \App\Models\StorageDestination::find($destId);
            if ($d && $d->is_active) {
                $destination = $d;
                break;
            }
        }
        if (! $destination) {
            return $this->fail('no active destination among replicas', ['candidates' => $candidates]);
        }

        $driver = StorageFactory::make($destination);
        $manifestRemote = $backup->file_path.'/'.BackupManifestV3::MANIFEST_FILENAME;
        $manifestLocal = $tempDir.'/manifest.json';

        try {
            $driver->download($manifestRemote, $manifestLocal);
        } catch (\Throwable $e) {
            return $this->fail("manifest.json unreadable: {$e->getMessage()}", ['remote' => $manifestRemote]);
        }

        try {
            $manifest = BackupManifestV3::decode(file_get_contents($manifestLocal));
        } catch (\Throwable $e) {
            return $this->fail($e->getMessage(), []);
        }

        $checks = [
            'format_version' => $manifest['format_version'],
            'file_count' => count($manifest['files']),
            'destination' => $destination->name,
        ];

        $shaConcat = '';
        foreach ($manifest['files'] as $entry) {
            $remoteName = $entry['name'];
            $localFile = $tempDir.'/'.basename($remoteName);

            try {
                $driver->download($backup->file_path.'/'.$remoteName, $localFile);
            } catch (\Throwable $e) {
                return $this->fail("file {$remoteName} unreachable: {$e->getMessage()}", $checks);
            }

            $size = (int) filesize($localFile);
            if ($size !== (int) ($entry['size'] ?? -1)) {
                @unlink($localFile);

                return $this->fail("file {$remoteName} size mismatch (expected {$entry['size']}, got {$size})", $checks);
            }

            $actualSha = hash_file('sha256', $localFile);
            if ($actualSha !== $entry['sha256']) {
                @unlink($localFile);

                return $this->fail("file {$remoteName} sha256 mismatch", $checks);
            }

            $shaConcat .= $entry['sha256'];
            @unlink($localFile);
        }

        $compositeChecksum = hash('sha256', $shaConcat);
        if ($backup->checksum && $compositeChecksum !== $backup->checksum) {
            return $this->fail("composite checksum mismatch (expected {$backup->checksum}, got {$compositeChecksum})", $checks);
        }

        @unlink($manifestLocal);

        return [
            'ok' => true,
            'message' => sprintf('multipart ok: %d files verified, composite sha256 matches', $checks['file_count']),
            'checks' => $checks,
        ];
    }

    /**
     * @param  array<string, mixed>  $checks
     * @return array{ok: false, message: string, checks: array<string, mixed>}
     */
    private function fail(string $reason, array $checks): array
    {
        Log::warning("IntegrityVerifier: {$reason}", $checks);

        return ['ok' => false, 'message' => $reason, 'checks' => $checks];
    }
}
