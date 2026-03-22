<?php

namespace App\Services\AppBackup;

use App\Models\AppBackup;
use App\Models\AppBackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AppBackupService
{
    public function createBackup(
        string $type = 'full',
        string $trigger = 'manual',
        ?int $storageDestinationId = null,
        array $options = [],
        ?string $notes = null,
    ): AppBackup {
        // Guard: no concurrent backups
        if (AppBackup::where('status', 'in_progress')->exists()) {
            throw new \RuntimeException('An application backup is already in progress.');
        }

        // Guard: check disk space (500MB minimum)
        $freeSpace = @disk_free_space(storage_path());
        if ($freeSpace !== false && $freeSpace < 500 * 1024 * 1024) {
            throw new \RuntimeException('Insufficient disk space. At least 500MB required.');
        }

        // Resolve components
        $components = $this->resolveComponents($type, $options);

        // Resolve storage destination
        $destination = $this->resolveStorageDestination($storageDestinationId);

        // Create backup record
        $backup = AppBackup::create([
            'type' => $type,
            'trigger' => $trigger,
            'components' => $components,
            'status' => 'in_progress',
            'progress' => 0,
            'storage_destination_id' => $destination?->id,
            'app_version' => config('app.version', '1.0.0'),
            'laravel_version' => app()->version(),
            'php_version' => PHP_VERSION,
            'sites_count' => Site::count(),
            'users_count' => User::count(),
            'started_at' => now(),
            'notes' => $notes,
            'log' => [],
        ]);

        $tempDir = storage_path('app/temp/app-backup-'.$backup->id);
        mkdir($tempDir, 0755, true);

        try {
            $componentSizes = [];

            // Database backup (10-40%)
            if (in_array('database', $components)) {
                $this->log($backup, 'Starting database backup...');
                $this->updateProgress($backup, 10);
                $dbPath = $this->backupDatabase($tempDir);
                $componentSizes['database'] = filesize($dbPath);
                $componentSizes['table_counts'] = $this->getTableRowCounts();
                $this->log($backup, 'Database backup completed ('.$this->formatBytes($componentSizes['database']).')');
                $this->updateProgress($backup, 40);
            }

            // .env backup (40-50%)
            if (in_array('env', $components)) {
                $this->log($backup, 'Backing up .env file...');
                $this->updateProgress($backup, 42);
                $envPath = $this->backupEnv($tempDir);
                $componentSizes['env'] = filesize($envPath);
                $this->log($backup, '.env backup completed');
                $this->updateProgress($backup, 50);
            }

            // Storage backup (50-70%)
            if (in_array('storage', $components)) {
                $this->log($backup, 'Backing up storage files...');
                $this->updateProgress($backup, 52);
                $storagePath = $this->backupStorage($tempDir);
                $componentSizes['storage'] = filesize($storagePath);
                $this->log($backup, 'Storage backup completed ('.$this->formatBytes($componentSizes['storage']).')');
                $this->updateProgress($backup, 70);
            }

            // Logs backup (70-80%)
            if (in_array('logs', $components)) {
                $this->log($backup, 'Backing up log files...');
                $this->updateProgress($backup, 72);
                $logsPath = $this->backupLogs($tempDir);
                $componentSizes['logs'] = filesize($logsPath);
                $this->log($backup, 'Logs backup completed');
                $this->updateProgress($backup, 80);
            }

            // Codebase backup (80-90%)
            if (in_array('codebase', $components)) {
                $this->log($backup, 'Backing up codebase...');
                $this->updateProgress($backup, 82);
                $codebasePath = $this->backupCodebase($tempDir);
                $componentSizes['codebase'] = filesize($codebasePath);
                $this->log($backup, 'Codebase backup completed ('.$this->formatBytes($componentSizes['codebase']).')');
                $this->updateProgress($backup, 90);
            }

            // Create final archive
            $this->log($backup, 'Creating final archive...');
            $this->updateProgress($backup, 92);

            $timestamp = now()->format('Ymd_His');
            $random = Str::random(6);
            $fileName = "simplead-backup-{$type}-{$timestamp}-{$random}.tar.gz";
            $archivePath = $tempDir.'/'.$fileName;

            $this->createArchive($tempDir, $archivePath, $components);

            // Encrypt if needed
            $config = AppBackupConfig::instance();
            if ($config->encrypt_backup && $config->encryption_password) {
                $this->log($backup, 'Encrypting backup...');
                $encryptedPath = $archivePath.'.enc';
                $this->encryptFile($archivePath, $encryptedPath, $config->encryption_password);
                unlink($archivePath);
                $archivePath = $encryptedPath;
                $fileName .= '.enc';
            }

            $fileSize = filesize($archivePath);
            $checksum = hash_file('sha256', $archivePath);

            // Upload to storage
            $this->log($backup, 'Uploading to storage...');
            $this->updateProgress($backup, 95);

            $remotePath = 'application-backups/'.$fileName;

            if ($destination) {
                $driver = StorageFactory::make($destination);
                $appBackupsPath = $destination->config['app_backups_path'] ?? null;

                if ($appBackupsPath) {
                    $absoluteRemotePath = rtrim($appBackupsPath, '/').'/'.$fileName;
                    $driver->uploadToAbsolutePath($archivePath, $absoluteRemotePath);
                    $remotePath = $absoluteRemotePath;
                } else {
                    $driver->upload($archivePath, $remotePath);
                }
                $destination->increment('used_bytes', $fileSize);
            } else {
                // Local fallback
                $fallbackDir = storage_path('app/backups/application');
                if (! is_dir($fallbackDir)) {
                    mkdir($fallbackDir, 0755, true);
                }
                copy($archivePath, $fallbackDir.'/'.$fileName);
                $remotePath = $fileName;
            }

            // Calculate expiry
            $expiresAt = null;
            if ($config->retention_type === 'days') {
                $expiresAt = now()->addDays($config->retention_value);
            }

            // Update record
            $backup->update([
                'status' => 'completed',
                'progress' => 100,
                'storage_path' => $remotePath,
                'file_name' => $fileName,
                'file_size' => $fileSize,
                'checksum' => $checksum,
                'component_sizes' => $componentSizes,
                'completed_at' => now(),
                'duration_seconds' => (int) $backup->started_at->diffInSeconds(now()),
                'expires_at' => $expiresAt,
            ]);

            $this->log($backup, "Backup completed successfully ({$this->formatBytes($fileSize)})");

            ActivityLogger::appBackupCompleted($fileName, $fileSize);

            // Update config
            $config->update([
                'last_backup_at' => now(),
                'last_backup_status' => 'completed',
            ]);

            // Apply retention
            $this->applyRetention();

            NotificationService::notifyAppEvent(
                'app_backup_completed',
                'Application Backup Completed',
                "Application backup completed successfully. File: {$fileName} ({$this->formatBytes($fileSize)})",
                [
                    'Type' => $type,
                    'Size' => $this->formatBytes($fileSize),
                    'Duration' => $backup->duration_formatted ?? 'N/A',
                ],
                'info',
            );

            return $backup;

        } catch (\Exception $e) {
            Log::error('Application backup failed: '.$e->getMessage());

            $backup->update([
                'status' => 'failed',
                'progress' => $backup->progress,
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'duration_seconds' => (int) $backup->started_at->diffInSeconds(now()),
            ]);

            $this->log($backup, 'FAILED: '.$e->getMessage());

            $config = AppBackupConfig::instance();
            $config->update(['last_backup_status' => 'failed']);

            ActivityLogger::appBackupFailed($e->getMessage());

            NotificationService::notifyAppEvent(
                'app_backup_failed',
                'Application Backup Failed',
                "Application backup failed: {$e->getMessage()}",
                ['Type' => $type, 'Error' => Str::limit($e->getMessage(), 200)],
                'critical',
            );

            throw $e;
        } finally {
            try {
                $this->cleanupDir($tempDir);
            } catch (\Exception $e) {
                Log::warning("Failed to cleanup temp dir: {$e->getMessage()}");
            }
        }
    }

    public function applyRetention(): void
    {
        $config = AppBackupConfig::instance();

        $query = AppBackup::where('status', 'completed')
            ->where('is_locked', false)
            ->orderByDesc('created_at');

        if ($config->retention_type === 'count') {
            $toDelete = $query->skip($config->retention_value)->get();
        } else {
            $cutoff = now()->subDays($config->retention_value);
            $toDelete = AppBackup::where('status', 'completed')
                ->where('is_locked', false)
                ->where('created_at', '<', $cutoff)
                ->get();
        }

        foreach ($toDelete as $oldBackup) {
            try {
                $this->deleteBackup($oldBackup);
            } catch (\Exception $e) {
                Log::warning("Failed to delete old app backup {$oldBackup->id}: {$e->getMessage()}");
            }
        }
    }

    public function downloadBackup(AppBackup $backup): string
    {
        $destination = $backup->storageDestination;

        if (! $destination) {
            // Local fallback
            $localPath = storage_path('app/backups/application/'.$backup->storage_path);
            if (! file_exists($localPath)) {
                throw new \RuntimeException('Backup file not found.');
            }

            return $localPath;
        }

        if ($destination->type === 'local') {
            $config = $destination->config ?? [];
            $basePath = rtrim($config['path'] ?? storage_path('backups'), '/');
            $filePath = $basePath.'/'.ltrim($backup->storage_path, '/');

            if (! file_exists($filePath)) {
                throw new \RuntimeException('Backup file not found.');
            }

            return $filePath;
        }

        // Remote: download to temp
        $tempDir = storage_path('app/temp/app-backup-download-'.$backup->id);
        if (! is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }

        $localPath = $tempDir.'/'.$backup->file_name;
        $driver = StorageFactory::make($destination);
        $driver->download($backup->storage_path, $localPath);

        return $localPath;
    }

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
            // Download the backup
            $archivePath = $this->downloadBackup($backup);

            // Decrypt if needed
            $config = AppBackupConfig::instance();
            if (Str::endsWith($archivePath, '.enc')) {
                if (! $config->encryption_password) {
                    throw new \RuntimeException('Encryption password is required to restore this backup.');
                }
                $decryptedPath = $tempDir.'/backup.tar.gz';
                $this->decryptFile($archivePath, $decryptedPath, $config->encryption_password);
                $archivePath = $decryptedPath;
            }

            // Extract archive
            $this->exec('tar -xzf '.escapeshellarg($archivePath).' -C '.escapeshellarg($tempDir));

            // Find database dump
            $dbFile = $tempDir.'/database.sql.gz';
            if (! file_exists($dbFile)) {
                throw new \RuntimeException('Database dump not found in backup archive.');
            }

            // Decompress
            $this->exec('gunzip -k '.escapeshellarg($dbFile));
            $sqlFile = $tempDir.'/database.sql';

            // Import
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

            // Reset PostgreSQL sequences after restore to avoid duplicate key errors
            if ($connection === 'pgsql') {
                $this->resetPostgresSequences();
            }

            // Verify restore
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
            $archivePath = $this->downloadBackup($backup);

            // Decrypt if encrypted
            $config = AppBackupConfig::instance();
            if (Str::endsWith($archivePath, '.enc')) {
                if (! $config->encryption_password) {
                    throw new \RuntimeException('Encryption password is required.');
                }
                $decryptedPath = $tempDir.'/backup.tar.gz';
                $this->decryptFile($archivePath, $decryptedPath, $config->encryption_password);
                $archivePath = $decryptedPath;
            }

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

    public function deleteBackup(AppBackup $backup): void
    {
        $destination = $backup->storageDestination;

        if ($destination && $backup->storage_path) {
            try {
                $driver = StorageFactory::make($destination);
                $driver->delete($backup->storage_path);
                $destination->decrement('used_bytes', max(0, $backup->file_size ?? 0));
            } catch (\Exception $e) {
                Log::warning("Failed to delete app backup file {$backup->storage_path}: {$e->getMessage()}");
            }
        } elseif (! $destination && $backup->storage_path) {
            // Local fallback
            $localPath = storage_path('app/backups/application/'.$backup->storage_path);
            if (file_exists($localPath)) {
                unlink($localPath);
            }
        }

        $backup->delete();
    }

    public function cleanupExpired(): void
    {
        AppBackup::expired()
            ->where('is_locked', false)
            ->each(function (AppBackup $backup) {
                try {
                    $this->deleteBackup($backup);
                } catch (\Exception $e) {
                    Log::warning("Failed to clean expired app backup {$backup->id}: {$e->getMessage()}");
                }
            });
    }

    // --- Private helpers ---

    protected function resolveComponents(string $type, array $options = []): array
    {
        $components = match ($type) {
            'full' => ['database', 'env', 'storage'],
            'database' => ['database'],
            'config' => ['env'],
            'storage' => ['storage'],
            default => ['database', 'env', 'storage'],
        };

        if (! empty($options['include_logs'])) {
            $components[] = 'logs';
        }
        if (! empty($options['include_codebase'])) {
            $components[] = 'codebase';
        }

        return array_unique($components);
    }

    protected function resolveStorageDestination(?int $storageDestinationId): ?StorageDestination
    {
        if ($storageDestinationId) {
            return StorageDestination::find($storageDestinationId);
        }

        $config = AppBackupConfig::instance();
        if ($config->storage_destination_id) {
            return StorageDestination::find($config->storage_destination_id);
        }

        return StorageDestination::where('is_default', true)
            ->where('is_active', true)
            ->first();
    }

    protected function backupDatabase(string $tempDir): string
    {
        $connection = config('database.default');
        $dbConfig = config("database.connections.{$connection}");
        $outputPath = $tempDir.'/database.sql.gz';

        $sqlPath = $tempDir.'/database.sql';

        if ($connection === 'pgsql') {
            $dumpCmd = sprintf(
                'PGPASSWORD=%s pg_dump --host=%s --port=%s --username=%s --no-owner --no-acl %s > %s',
                escapeshellarg($dbConfig['password']),
                escapeshellarg($dbConfig['host']),
                escapeshellarg($dbConfig['port']),
                escapeshellarg($dbConfig['username']),
                escapeshellarg($dbConfig['database']),
                escapeshellarg($sqlPath)
            );
        } else {
            $dumpCmd = sprintf(
                'mysqldump --single-transaction --routines --triggers --events --quick --lock-tables=false --host=%s --port=%s --user=%s --password=%s %s > %s',
                escapeshellarg($dbConfig['host']),
                escapeshellarg($dbConfig['port']),
                escapeshellarg($dbConfig['username']),
                escapeshellarg($dbConfig['password']),
                escapeshellarg($dbConfig['database']),
                escapeshellarg($sqlPath)
            );
        }

        $this->exec($dumpCmd);

        if (! file_exists($sqlPath) || filesize($sqlPath) === 0) {
            throw new \RuntimeException('Database dump failed or produced empty file.');
        }

        $this->exec(sprintf('gzip -9 %s', escapeshellarg($sqlPath)));

        if (! file_exists($outputPath)) {
            throw new \RuntimeException('Database dump compression failed.');
        }

        return $outputPath;
    }

    protected function backupEnv(string $tempDir): string
    {
        $envPath = base_path('.env');

        if (file_exists($envPath)) {
            $envContent = file_get_contents($envPath);
        } else {
            // In Docker, .env is excluded from the image and vars are injected
            // via env_file in docker-compose. Reconstruct from the environment.
            $envContent = $this->reconstructEnvFromEnvironment();
        }

        $outputPath = $tempDir.'/env.encrypted';
        $encrypted = encrypt($envContent);
        file_put_contents($outputPath, $encrypted);

        return $outputPath;
    }

    protected function reconstructEnvFromEnvironment(): string
    {
        $keys = [
            'APP_NAME', 'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL',
            'LOG_CHANNEL', 'LOG_LEVEL',
            'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD',
            'REDIS_HOST', 'REDIS_PASSWORD', 'REDIS_PORT',
            'MAIL_MAILER', 'MAIL_HOST', 'MAIL_PORT', 'MAIL_USERNAME', 'MAIL_PASSWORD',
            'MAIL_ENCRYPTION', 'MAIL_FROM_ADDRESS', 'MAIL_FROM_NAME',
            'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET',
            'QUEUE_CONNECTION', 'SESSION_DRIVER', 'CACHE_STORE',
            'FILESYSTEM_DISK',
        ];

        $lines = [];
        foreach ($keys as $key) {
            $value = env($key);
            if ($value !== null) {
                $value = str_contains((string) $value, ' ') ? "\"$value\"" : $value;
                $lines[] = "$key=$value";
            }
        }

        return implode("\n", $lines)."\n";
    }

    protected function backupStorage(string $tempDir): string
    {
        $outputPath = $tempDir.'/storage.tar.gz';
        $storagePath = storage_path('app');

        $excludes = [
            '--exclude=temp',
            '--exclude=app-backup-*',
            '--exclude=framework',
            '--exclude=backups/application',
        ];

        $cmd = sprintf(
            'tar -czf %s -C %s %s .',
            escapeshellarg($outputPath),
            escapeshellarg($storagePath),
            implode(' ', $excludes)
        );

        $this->exec($cmd);

        // Also include public/uploads if it exists
        $uploadsPath = public_path('uploads');
        if (is_dir($uploadsPath)) {
            $uploadsArchive = $tempDir.'/uploads.tar.gz';
            $this->exec(sprintf(
                'tar -czf %s -C %s .',
                escapeshellarg($uploadsArchive),
                escapeshellarg($uploadsPath)
            ));
        }

        return $outputPath;
    }

    protected function backupLogs(string $tempDir): string
    {
        $outputPath = $tempDir.'/logs.tar.gz';
        $logsPath = storage_path('logs');

        if (! is_dir($logsPath)) {
            // Create empty archive
            file_put_contents($outputPath, '');

            return $outputPath;
        }

        $this->exec(sprintf(
            'tar -czf %s -C %s .',
            escapeshellarg($outputPath),
            escapeshellarg($logsPath)
        ));

        return $outputPath;
    }

    protected function backupCodebase(string $tempDir): string
    {
        $outputPath = $tempDir.'/codebase.tar.gz';
        $basePath = base_path();

        $excludes = [
            '--exclude=vendor',
            '--exclude=node_modules',
            '--exclude=.git',
            '--exclude=storage/app/temp',
            '--exclude=storage/app/backups',
            '--exclude=storage/logs',
            '--exclude=storage/framework',
            '--exclude=bootstrap/cache',
        ];

        $this->exec(sprintf(
            'tar -czf %s -C %s %s .',
            escapeshellarg($outputPath),
            escapeshellarg($basePath),
            implode(' ', $excludes)
        ));

        return $outputPath;
    }

    protected function createArchive(string $tempDir, string $archivePath, array $components): void
    {
        $files = [];
        $fileMap = [
            'database' => 'database.sql.gz',
            'env' => 'env.encrypted',
            'storage' => 'storage.tar.gz',
            'logs' => 'logs.tar.gz',
            'codebase' => 'codebase.tar.gz',
        ];

        foreach ($components as $component) {
            if (isset($fileMap[$component])) {
                $file = $tempDir.'/'.$fileMap[$component];
                if (file_exists($file)) {
                    $files[] = $fileMap[$component];
                }
            }
        }

        // Also include uploads archive if it exists
        if (file_exists($tempDir.'/uploads.tar.gz')) {
            $files[] = 'uploads.tar.gz';
        }

        $this->exec(sprintf(
            'tar -czf %s -C %s %s',
            escapeshellarg($archivePath),
            escapeshellarg($tempDir),
            implode(' ', array_map('escapeshellarg', $files))
        ));
    }

    protected function encryptFile(string $inputPath, string $outputPath, string $password): void
    {
        $this->exec(sprintf(
            'openssl enc -aes-256-cbc -salt -pbkdf2 -in %s -out %s -pass pass:%s',
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            escapeshellarg($password)
        ));
    }

    protected function decryptFile(string $inputPath, string $outputPath, string $password): void
    {
        $this->exec(sprintf(
            'openssl enc -aes-256-cbc -d -pbkdf2 -in %s -out %s -pass pass:%s',
            escapeshellarg($inputPath),
            escapeshellarg($outputPath),
            escapeshellarg($password)
        ));
    }

    protected function exec(string $command): string
    {
        $output = [];
        $returnVar = 0;
        exec($command.' 2>&1', $output, $returnVar);

        if ($returnVar !== 0) {
            $outputStr = implode("\n", $output);
            throw new \RuntimeException("Command failed (exit code {$returnVar}): {$outputStr}");
        }

        return implode("\n", $output);
    }

    protected function updateProgress(AppBackup $backup, int $progress): void
    {
        $backup->update(['progress' => $progress]);
    }

    protected function log(AppBackup $backup, string $message): void
    {
        $log = $backup->log ?? [];
        $log[] = [
            'time' => now()->format('H:i:s'),
            'message' => $message,
        ];
        $backup->update(['log' => $log]);
    }

    protected function getTableRowCounts(): array
    {
        $counts = [];
        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");

        foreach ($tables as $table) {
            try {
                if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table->tablename)) {
                    continue;
                }
                $counts[$table->tablename] = DB::table($table->tablename)->count();
            } catch (\Exception $e) {
                // Skip tables that can't be counted
            }
        }

        return $counts;
    }

    protected function verifyRestore(AppBackup $backup): array
    {
        $currentCounts = $this->getTableRowCounts();
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
                // Values come from pg_class system catalog, safe to interpolate
                DB::statement(sprintf(
                    'SELECT setval(%s, COALESCE((SELECT MAX(%s) FROM %s), 1))',
                    DB::getPdo()->quote($seq->sequence_name),
                    '"'.$seq->column_name.'"',
                    '"'.$seq->table_name.'"',
                ));
            } catch (\Exception $e) {
                Log::warning("Failed to reset sequence {$seq->sequence_name}: {$e->getMessage()}");
            }
        }
    }

    protected function cleanupDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        }

        rmdir($dir);
    }

    protected function formatBytes(int $bytes): string
    {
        return \App\Helpers\FormatHelper::bytes($bytes);
    }
}
