<?php

declare(strict_types=1);

namespace App\Services\AppBackup;

use App\Models\AppBackup;
use App\Services\ActivityLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AppBackupRestorer
{
    use AppBackupHelpers;

    public function __construct(
        private AppBackupDownloader $downloader,
    ) {}

    public function restoreDatabase(AppBackup $backup): array
    {
        $components = $backup->components ?? [];
        if (! in_array('database', $components)) {
            throw new \RuntimeException('This backup does not contain a database component.');
        }

        $tempDir = storage_path('app/temp/app-restore-'.$backup->id);
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        try {
            $archivePath = $this->downloader->download($backup);

            $this->exec('tar -xzf '.escapeshellarg($archivePath).' -C '.escapeshellarg($tempDir));

            $dbFile = $tempDir.'/database.sql.gz';
            if (! file_exists($dbFile)) {
                throw new \RuntimeException('Database dump not found in backup archive.');
            }

            $this->exec('gunzip -k '.escapeshellarg($dbFile));
            $sqlFile = $tempDir.'/database.sql';

            $connection = config('database.default');

            if ($connection === 'pgsql') {
                $this->restorePostgres($sqlFile, $tempDir, $backup);
                $this->resetPostgresSequences();
            } else {
                $dbConfig = config("database.connections.{$connection}");
                $cmd = sprintf(
                    'mysql --host=%s --port=%s --user=%s --password=%s %s < %s',
                    escapeshellarg($dbConfig['host']),
                    escapeshellarg($dbConfig['port']),
                    escapeshellarg($dbConfig['username']),
                    escapeshellarg($dbConfig['password']),
                    escapeshellarg($dbConfig['database']),
                    escapeshellarg($sqlFile)
                );
                $this->exec($cmd);
            }

            $verification = $this->verifyRestore($backup);

            ActivityLogger::appDatabaseRestored($backup->created_at->format('Y-m-d H:i'));

            return $verification;

        } finally {
            $this->cleanupDir($tempDir);
        }
    }

    /**
     * P1-13: restore the platform's OWN Postgres database onto the live, running
     * database — safely.
     *
     * Guarantees:
     *  - A pre-restore safety dump is taken FIRST; the destructive restore is
     *    refused if that dump cannot be produced (never wipe without a net).
     *  - The restore runs on the DIRECT (non-pooled) connection so schema DDL
     *    bypasses PgBouncer transaction pooling (which breaks multi-statement DDL).
     *  - `DROP SCHEMA public CASCADE; CREATE SCHEMA public;` then the dump run in a
     *    SINGLE transaction with `ON_ERROR_STOP=1`: the plain dump's `CREATE TABLE`
     *    statements can no longer collide with existing objects, and any failure
     *    rolls the whole thing back — the live DB is left exactly as it was rather
     *    than in a half-applied state.
     */
    protected function restorePostgres(string $sqlFile, string $tempDir, AppBackup $backup): void
    {
        $pg = $this->directConnectionConfig();

        // Guard: take a safety dump of the CURRENT database before we touch it.
        $safetyPath = $this->createSafetyDump($pg, $backup);

        $resetSqlPath = $tempDir.'/00-reset-schema.sql';
        file_put_contents($resetSqlPath, $this->schemaResetSql());

        $this->exec($this->buildPgRestoreCommand($pg, $resetSqlPath, $sqlFile));

        Log::info('App self-restore completed on direct connection', [
            'backup_id' => $backup->id,
            'safety_dump' => $safetyPath,
        ]);
    }

    /**
     * Resolve the connection config used for the physical restore. Prefer the
     * direct (non-pooled) Postgres connection; fall back to the default pgsql
     * connection when the direct one is not configured.
     *
     * @return array<string, mixed>
     */
    protected function directConnectionConfig(): array
    {
        $direct = config('database.connections.pgsql_direct');
        if (is_array($direct) && ! empty($direct['host'])) {
            return $direct;
        }

        return config('database.connections.pgsql');
    }

    /**
     * SQL that cleanly empties the target database before the dump is applied,
     * so a plain (non---clean) dump restores without "relation already exists".
     */
    protected function schemaResetSql(): string
    {
        return "DROP SCHEMA IF EXISTS public CASCADE;\nCREATE SCHEMA public;\n";
    }

    /**
     * Build the atomic restore command: schema reset + dump applied in ONE
     * transaction (ON_ERROR_STOP + --single-transaction) on the given connection.
     *
     * @param  array<string, mixed>  $pg
     */
    protected function buildPgRestoreCommand(array $pg, string $resetSqlPath, string $sqlFile): string
    {
        return sprintf(
            'PGPASSWORD=%s psql --set ON_ERROR_STOP=1 --single-transaction --host=%s --port=%s --username=%s %s -f %s -f %s',
            escapeshellarg((string) $pg['password']),
            escapeshellarg((string) $pg['host']),
            escapeshellarg((string) $pg['port']),
            escapeshellarg((string) $pg['username']),
            escapeshellarg((string) $pg['database']),
            escapeshellarg($resetSqlPath),
            escapeshellarg($sqlFile)
        );
    }

    /**
     * Build the pre-restore safety-dump command (self-cleaning --clean --if-exists
     * dump of the live DB, gzipped).
     *
     * @param  array<string, mixed>  $pg
     */
    protected function buildSafetyDumpCommand(array $pg, string $outPath): string
    {
        return sprintf(
            'PGPASSWORD=%s pg_dump --no-owner --no-acl --clean --if-exists --host=%s --port=%s --username=%s %s | gzip -9 > %s',
            escapeshellarg((string) $pg['password']),
            escapeshellarg((string) $pg['host']),
            escapeshellarg((string) $pg['port']),
            escapeshellarg((string) $pg['username']),
            escapeshellarg((string) $pg['database']),
            escapeshellarg($outPath)
        );
    }

    /**
     * Produce a pre-restore safety dump and return its path. Throws (aborting the
     * restore) if the dump cannot be produced or is empty — the destructive
     * restore must never proceed without a recovery point.
     *
     * @param  array<string, mixed>  $pg
     */
    protected function createSafetyDump(array $pg, AppBackup $backup): string
    {
        $dir = storage_path('app/app-restore-safety');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $outPath = $dir.'/pre-restore-'.$backup->id.'-'.now()->format('Y-m-d-His').'.sql.gz';

        try {
            $this->exec($this->buildSafetyDumpCommand($pg, $outPath));
        } catch (\RuntimeException $e) {
            throw new \RuntimeException('Aborting restore: failed to create pre-restore safety dump — '.$e->getMessage(), 0, $e);
        }

        $this->assertSafetyDumpValid($outPath);

        return $outPath;
    }

    /**
     * Guard: a safety dump that is missing or empty is not a recovery point.
     */
    protected function assertSafetyDumpValid(string $path): void
    {
        if (! file_exists($path) || filesize($path) === 0) {
            throw new \RuntimeException('Aborting restore: pre-restore safety dump is missing or empty ('.$path.').');
        }
    }

    public function viewEnv(AppBackup $backup): string
    {
        $components = $backup->components ?? [];
        if (! in_array('env', $components)) {
            throw new \RuntimeException('This backup does not contain an .env component.');
        }

        $tempDir = storage_path('app/temp/app-env-view-'.$backup->id);
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        try {
            $archivePath = $this->downloader->download($backup);

            $this->exec('tar -xzf '.escapeshellarg($archivePath).' -C '.escapeshellarg($tempDir));

            $envFile = $tempDir.'/env.encrypted';
            if (! file_exists($envFile)) {
                throw new \RuntimeException('.env file not found in backup archive.');
            }

            return decrypt(file_get_contents($envFile));

        } finally {
            $this->cleanupDir($tempDir);
        }
    }

    protected function verifyRestore(AppBackup $backup): array
    {
        $creator = app(AppBackupCreator::class);
        $currentCounts = $creator->getTableRowCounts();
        $backupCounts = $backup->component_sizes['table_counts'] ?? [];

        $verification = [
            'status' => 'ok',
            'tables_checked' => count($currentCounts),
            'tables_matched' => 0,
            'tables_different' => 0,
            'details' => [],
        ];

        if (empty($backupCounts)) {
            $verification['status'] = 'no_baseline';
            $verification['message'] = 'Restore completed. No row counts stored in backup for comparison.';
            $verification['current_counts'] = $currentCounts;

            return $verification;
        }

        foreach ($backupCounts as $table => $expectedCount) {
            $actualCount = $currentCounts[$table] ?? null;

            if ($actualCount === null) {
                $verification['details'][] = [
                    'table' => $table,
                    'expected' => $expectedCount,
                    'actual' => null,
                    'status' => 'missing',
                ];
                $verification['tables_different']++;
            } elseif ((int) $actualCount === (int) $expectedCount) {
                $verification['tables_matched']++;
            } else {
                $verification['details'][] = [
                    'table' => $table,
                    'expected' => $expectedCount,
                    'actual' => $actualCount,
                    'status' => 'mismatch',
                ];
                $verification['tables_different']++;
            }
        }

        if ($verification['tables_different'] > 0) {
            $verification['status'] = 'warning';
        }

        return $verification;
    }

    protected function resetPostgresSequences(): void
    {
        $sequences = DB::select("
            SELECT s.relname AS sequence_name, t.relname AS table_name, a.attname AS column_name
            FROM pg_class s
            JOIN pg_depend d ON d.objid = s.oid
            JOIN pg_class t ON d.refobjid = t.oid
            JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = d.refobjsubid
            WHERE s.relkind = 'S'
        ");

        foreach ($sequences as $seq) {
            try {
                DB::statement(sprintf(
                    'SELECT setval(%s, COALESCE((SELECT MAX(%s) FROM %s), 1))',
                    DB::getPdo()->quote($seq->sequence_name),
                    '"'.$seq->column_name.'"',
                    '"'.$seq->table_name.'"',
                ));
            } catch (QueryException $e) {
                Log::warning("Failed to reset sequence {$seq->sequence_name}: {$e->getMessage()}");
            }
        }
    }
}
