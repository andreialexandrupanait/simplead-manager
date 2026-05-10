<?php

declare(strict_types=1);

namespace App\Services\Backup;

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
     * @param  array<string, mixed>  $checks
     * @return array{ok: false, message: string, checks: array<string, mixed>}
     */
    private function fail(string $reason, array $checks): array
    {
        Log::warning("IntegrityVerifier: {$reason}", $checks);

        return ['ok' => false, 'message' => $reason, 'checks' => $checks];
    }
}
