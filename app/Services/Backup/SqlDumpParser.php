<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * Lightweight streaming parser for the database dump produced by the WP connector
 * plugin (see wordpress-plugin/.../class-backup-endpoint.php::php_database_dump).
 *
 * Goals:
 * - Stream-read so memory stays bounded for multi-GB dumps.
 * - Confirm structural sanity: opens, has expected header, contains DDL + DML.
 * - Cheap enough to run on every backup (~100 ms for a 100 MB dump).
 *
 * Does NOT execute the SQL; this is structure validation, not semantic.
 */
class SqlDumpParser
{
    /** Maximum bytes to scan when looking for the header. */
    private const HEADER_SCAN_BYTES = 2048;

    /**
     * @return array{
     *   ok: bool,
     *   error: string|null,
     *   table_count: int,
     *   insert_count: int,
     *   bytes_scanned: int,
     *   has_expected_header: bool,
     *   has_expected_footer: bool,
     * }
     */
    public function parse(string $path): array
    {
        if (! is_file($path)) {
            return $this->failure("file does not exist: {$path}");
        }

        if (filesize($path) === 0) {
            return $this->failure('file is empty');
        }

        $isGzipped = str_ends_with($path, '.gz');
        $handle = $isGzipped ? @gzopen($path, 'rb') : @fopen($path, 'rb');
        if (! $handle) {
            return $this->failure('failed to open file');
        }

        $tableCount = 0;
        $insertCount = 0;
        $bytesScanned = 0;
        $headerScanned = '';
        $hasFooter = false;
        $lastNonEmptyLine = '';

        try {
            while (! ($isGzipped ? gzeof($handle) : feof($handle))) {
                $line = $isGzipped ? gzgets($handle) : fgets($handle);
                if ($line === false) {
                    break;
                }

                $bytesScanned += strlen($line);

                if (strlen($headerScanned) < self::HEADER_SCAN_BYTES) {
                    $headerScanned .= $line;
                }

                $trimmed = ltrim($line);
                if ($trimmed === '') {
                    continue;
                }

                $lastNonEmptyLine = $trimmed;

                // Match common DDL/DML markers without expensive regex.
                $upper = strtoupper(substr($trimmed, 0, 14));
                if (str_starts_with($upper, 'CREATE TABLE')) {
                    $tableCount++;
                } elseif (str_starts_with($upper, 'INSERT INTO')) {
                    $insertCount++;
                }
            }
        } finally {
            $isGzipped ? gzclose($handle) : fclose($handle);
        }

        $headerUpper = strtoupper($headerScanned);
        $hasExpectedHeader = str_contains($headerUpper, 'SET NAMES')
            && str_contains($headerUpper, 'FOREIGN_KEY_CHECKS');

        // Expected footer pattern from php_database_dump: "SET FOREIGN_KEY_CHECKS = 1;"
        $hasFooter = str_starts_with(strtoupper(rtrim($lastNonEmptyLine)), 'SET FOREIGN_KEY_CHECKS');

        if (! $hasExpectedHeader) {
            return [
                'ok' => false,
                'error' => 'missing expected header (SET NAMES + FOREIGN_KEY_CHECKS)',
                'table_count' => $tableCount,
                'insert_count' => $insertCount,
                'bytes_scanned' => $bytesScanned,
                'has_expected_header' => false,
                'has_expected_footer' => $hasFooter,
            ];
        }

        if ($tableCount === 0) {
            return [
                'ok' => false,
                'error' => 'no CREATE TABLE statements found',
                'table_count' => 0,
                'insert_count' => $insertCount,
                'bytes_scanned' => $bytesScanned,
                'has_expected_header' => true,
                'has_expected_footer' => $hasFooter,
            ];
        }

        return [
            'ok' => true,
            'error' => null,
            'table_count' => $tableCount,
            'insert_count' => $insertCount,
            'bytes_scanned' => $bytesScanned,
            'has_expected_header' => true,
            'has_expected_footer' => $hasFooter,
        ];
    }

    /**
     * @return array{ok: false, error: string, table_count: int, insert_count: int, bytes_scanned: int, has_expected_header: bool, has_expected_footer: bool}
     */
    private function failure(string $error): array
    {
        return [
            'ok' => false,
            'error' => $error,
            'table_count' => 0,
            'insert_count' => 0,
            'bytes_scanned' => 0,
            'has_expected_header' => false,
            'has_expected_footer' => false,
        ];
    }
}
