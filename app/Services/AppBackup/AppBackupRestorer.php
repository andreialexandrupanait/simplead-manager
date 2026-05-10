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
        private AppBackupService $backupService,
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
            $archivePath = $this->backupService->downloadBackup($backup);

            $this->exec('tar -xzf '.escapeshellarg($archivePath).' -C '.escapeshellarg($tempDir));

            $dbFile = $tempDir.'/database.sql.gz';
            if (! file_exists($dbFile)) {
                throw new \RuntimeException('Database dump not found in backup archive.');
            }

            $this->exec('gunzip -k '.escapeshellarg($dbFile));
            $sqlFile = $tempDir.'/database.sql';

            $connection = config('database.default');
            $dbConfig = config("database.connections.{$connection}");

            if ($connection === 'pgsql') {
                $cmd = sprintf(
                    'PGPASSWORD=%s psql --set ON_ERROR_STOP=1 --host=%s --port=%s --username=%s %s < %s',
                    escapeshellarg($dbConfig['password']),
                    escapeshellarg($dbConfig['host']),
                    escapeshellarg($dbConfig['port']),
                    escapeshellarg($dbConfig['username']),
                    escapeshellarg($dbConfig['database']),
                    escapeshellarg($sqlFile)
                );
            } else {
                $cmd = sprintf(
                    'mysql --host=%s --port=%s --user=%s --password=%s %s < %s',
                    escapeshellarg($dbConfig['host']),
                    escapeshellarg($dbConfig['port']),
                    escapeshellarg($dbConfig['username']),
                    escapeshellarg($dbConfig['password']),
                    escapeshellarg($dbConfig['database']),
                    escapeshellarg($sqlFile)
                );
            }

            $this->exec($cmd);

            if ($connection === 'pgsql') {
                $this->resetPostgresSequences();
            }

            $verification = $this->verifyRestore($backup);

            ActivityLogger::appDatabaseRestored($backup->created_at->format('Y-m-d H:i'));

            return $verification;

        } finally {
            $this->cleanupDir($tempDir);
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
            $archivePath = $this->backupService->downloadBackup($backup);

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
