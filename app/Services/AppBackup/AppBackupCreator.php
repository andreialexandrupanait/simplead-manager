<?php

declare(strict_types=1);

namespace App\Services\AppBackup;

use App\Models\AppBackup;
use App\Models\AppBackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use App\Services\ActivityLogger;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\Notifications\NotificationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AppBackupCreator
{
    use AppBackupHelpers;

    public function create(
        string $type = 'full',
        string $trigger = 'manual',
        ?int $storageDestinationId = null,
        array $options = [],
        ?string $notes = null,
    ): AppBackup {
        // P1-38: self-heal a wedged queue before the in-progress guard. A worker
        // killed mid-run (timeout/OOM/deploy) leaves the row in_progress forever,
        // permanently blocking all future app backups. Fail any row whose
        // heartbeat is older than the recovery threshold so a fresh attempt is
        // never blocked by a dead one (the scheduled app-backups:recover-stuck
        // command is the background counterpart).
        $this->failStuckAppBackups();

        if (AppBackup::where('status', 'in_progress')->exists()) {
            throw new \RuntimeException('An application backup is already in progress.');
        }

        $freeSpace = @disk_free_space(storage_path());
        if ($freeSpace !== false && $freeSpace < 500 * 1024 * 1024) {
            throw new \RuntimeException('Insufficient disk space. At least 500MB required.');
        }

        $components = $this->resolveComponents($type, $options);
        $destination = $this->resolveStorageDestination($storageDestinationId);
        $config = AppBackupConfig::instance();

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

            if (in_array('database', $components)) {
                $this->log($backup, 'Starting database backup...');
                $this->updateProgress($backup, 10);
                $dbPath = $this->backupDatabase($tempDir);
                $componentSizes['database'] = filesize($dbPath);
                $componentSizes['table_counts'] = $this->getTableRowCounts();
                $this->log($backup, 'Database backup completed ('.$this->formatBytes($componentSizes['database']).')');
                $this->updateProgress($backup, 40);
            }

            if (in_array('env', $components)) {
                $this->log($backup, 'Backing up .env file...');
                $this->updateProgress($backup, 42);
                $envPath = $this->backupEnv($tempDir);
                $componentSizes['env'] = filesize($envPath);
                $this->log($backup, '.env backup completed');
                $this->updateProgress($backup, 50);
            }

            if (in_array('storage', $components)) {
                $this->log($backup, 'Backing up storage files...');
                $this->updateProgress($backup, 52);
                $storagePath = $this->backupStorage($tempDir);
                $componentSizes['storage'] = filesize($storagePath);
                $this->log($backup, 'Storage backup completed ('.$this->formatBytes($componentSizes['storage']).')');
                $this->updateProgress($backup, 70);
            }

            if (in_array('logs', $components)) {
                $this->log($backup, 'Backing up log files...');
                $this->updateProgress($backup, 72);
                $logsPath = $this->backupLogs($tempDir);
                $componentSizes['logs'] = filesize($logsPath);
                $this->log($backup, 'Logs backup completed');
                $this->updateProgress($backup, 80);
            }

            if (in_array('codebase', $components)) {
                $this->log($backup, 'Backing up codebase...');
                $this->updateProgress($backup, 82);
                $codebasePath = $this->backupCodebase($tempDir);
                $componentSizes['codebase'] = filesize($codebasePath);
                $this->log($backup, 'Codebase backup completed ('.$this->formatBytes($componentSizes['codebase']).')');
                $this->updateProgress($backup, 90);
            }

            $this->log($backup, 'Creating final archive...');
            $this->updateProgress($backup, 92);

            $timestamp = now()->format('Ymd_His');
            $random = Str::random(6);
            $fileName = "simplead-backup-{$type}-{$timestamp}-{$random}.tar.gz";
            $archivePath = $tempDir.'/'.$fileName;

            $this->createArchive($tempDir, $archivePath, $components);

            $fileSize = filesize($archivePath);
            $checksum = hash_file('sha256', $archivePath);

            $this->log($backup, 'Uploading to storage...');
            $this->updateProgress($backup, 95);

            $remotePath = 'application-backups/'.$fileName;

            // Tracks whether the backup actually left the host. A degraded
            // backup (local-only fallback) is NOT a disaster-recovery-grade
            // backup and must never be reported as a completed off-site upload.
            $degraded = false;

            if ($destination) {
                $driver = StorageFactory::make($destination);
                $appBackupsPath = $destination->config['app_backups_path'] ?? null;

                if ($appBackupsPath) {
                    $absoluteRemotePath = rtrim($appBackupsPath, '/').'/'.$fileName;
                    $driver->uploadToAbsolutePath($archivePath, $absoluteRemotePath);
                    $remotePath = $absoluteRemotePath;
                } else {
                    $driver->upload($archivePath, $remotePath);

                    // Verify the remote destination actually received the file.
                    // A silent upload() that did not persist must fail loudly
                    // rather than be recorded as a successful off-site backup.
                    // (Only run for base-path uploads, whose exists() semantics
                    // are consistent across drivers.)
                    if (! $driver->exists($remotePath)) {
                        throw new \RuntimeException(
                            "Off-site upload unverified: remote destination did not confirm {$remotePath}."
                        );
                    }
                }
                $destination->increment('used_bytes', $fileSize);
            } else {
                // No remote destination resolved — keep a local copy (better
                // than nothing) but flag the backup DEGRADED and alert loudly.
                // The platform's own DR is unproven when backups never leave
                // the host, so this must not masquerade as a clean success.
                $degraded = true;
                $fallbackDir = storage_path('app/backups/application');
                if (! is_dir($fallbackDir)) {
                    mkdir($fallbackDir, 0755, true);
                }
                copy($archivePath, $fallbackDir.'/'.$fileName);
                $remotePath = $fileName;
            }

            $expiresAt = null;
            if ($config->retention_type === 'days') {
                $expiresAt = now()->addDays($config->retention_value);
            }

            $backup->update([
                'status' => $degraded ? 'degraded' : 'completed',
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

            $config->update([
                'last_backup_at' => now(),
                'last_backup_status' => $degraded ? 'degraded' : 'completed',
            ]);

            if ($degraded) {
                $this->log($backup, "DEGRADED: backup completed LOCALLY ONLY — no remote destination configured. This backup did not leave the host ({$this->formatBytes($fileSize)}).");

                ActivityLogger::appBackupFailed('Degraded: local-only backup (no remote destination)');

                NotificationService::notifyAppEvent(
                    'app_backup_degraded',
                    'Application Backup Degraded (Local-Only)',
                    "Application backup completed but stayed on the host — no remote storage destination is configured. Disaster recovery is NOT protected. File: {$fileName} ({$this->formatBytes($fileSize)}).",
                    [
                        'Type' => $type,
                        'Size' => $this->formatBytes($fileSize),
                        'Location' => 'Local host only (no off-site copy)',
                    ],
                    'critical',
                );

                return $backup;
            }

            $this->log($backup, "Backup completed successfully ({$this->formatBytes($fileSize)})");

            ActivityLogger::appBackupCompleted($fileName, $fileSize);

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

        } catch (\Throwable $e) {
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
            } catch (\RuntimeException $e) {
                Log::warning("Failed to cleanup temp dir: {$e->getMessage()}");
            }
        }
    }

    /**
     * P1-38: mark app backups stuck in_progress past the recovery threshold as
     * failed, so a dead worker's row can never permanently wedge the queue.
     */
    protected function failStuckAppBackups(): void
    {
        $stale = AppBackup::where('status', 'in_progress')
            ->where('updated_at', '<', now()->subMinutes(\App\Console\Commands\RecoverStuckAppBackups::STALE_AFTER_MINUTES))
            ->get();

        foreach ($stale as $backup) {
            $ageMinutes = (int) $backup->updated_at->diffInMinutes(now());
            $backup->update([
                'status' => 'failed',
                'error_message' => "App backup worker died without cleanup — no progress for {$ageMinutes} minutes (auto-recovered before a new run).",
                'completed_at' => now(),
                'duration_seconds' => $backup->started_at ? (int) $backup->started_at->diffInSeconds(now()) : null,
            ]);
            Log::warning("AppBackupCreator: recovered stuck app backup {$backup->id} ({$ageMinutes}m silent) before starting a new one");
        }
    }

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
            $envContent = $this->reconstructEnvFromEnvironment();
        }

        $outputPath = $tempDir.'/env.encrypted';
        $encrypted = encrypt($envContent);
        file_put_contents($outputPath, $encrypted);

        return $outputPath;
    }

    protected function reconstructEnvFromEnvironment(): string
    {
        $mapping = [
            'APP_NAME' => config('app.name'),
            'APP_ENV' => config('app.env'),
            'APP_KEY' => config('app.key'),
            'APP_DEBUG' => config('app.debug') ? 'true' : 'false',
            'APP_URL' => config('app.url'),
            'LOG_CHANNEL' => config('logging.default'),
            'LOG_LEVEL' => config('logging.channels.'.config('logging.default').'.level'),
            'DB_CONNECTION' => config('database.default'),
            'DB_HOST' => config('database.connections.pgsql.host'),
            'DB_PORT' => config('database.connections.pgsql.port'),
            'DB_DATABASE' => config('database.connections.pgsql.database'),
            'DB_USERNAME' => config('database.connections.pgsql.username'),
            'DB_PASSWORD' => config('database.connections.pgsql.password'),
            'REDIS_HOST' => config('database.redis.default.host'),
            'REDIS_PASSWORD' => config('database.redis.default.password'),
            'REDIS_PORT' => config('database.redis.default.port'),
            'MAIL_MAILER' => config('mail.default'),
            'MAIL_HOST' => config('mail.mailers.smtp.host'),
            'MAIL_PORT' => config('mail.mailers.smtp.port'),
            'MAIL_USERNAME' => config('mail.mailers.smtp.username'),
            'MAIL_PASSWORD' => config('mail.mailers.smtp.password'),
            'MAIL_ENCRYPTION' => config('mail.mailers.smtp.encryption'),
            'MAIL_FROM_ADDRESS' => config('mail.from.address'),
            'MAIL_FROM_NAME' => config('mail.from.name'),
            'AWS_ACCESS_KEY_ID' => config('filesystems.disks.s3.key'),
            'AWS_SECRET_ACCESS_KEY' => config('filesystems.disks.s3.secret'),
            'AWS_DEFAULT_REGION' => config('filesystems.disks.s3.region'),
            'AWS_BUCKET' => config('filesystems.disks.s3.bucket'),
            'QUEUE_CONNECTION' => config('queue.default'),
            'SESSION_DRIVER' => config('session.driver'),
            'CACHE_STORE' => config('cache.default'),
            'FILESYSTEM_DISK' => config('filesystems.default'),
        ];

        $lines = [];
        foreach ($mapping as $key => $value) {
            if ($value !== null) {
                $value = (string) $value;
                $value = str_contains($value, ' ') ? "\"$value\"" : $value;
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

    public function getTableRowCounts(): array
    {
        $counts = [];
        $tables = DB::select("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");

        foreach ($tables as $table) {
            try {
                if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table->tablename)) {
                    continue;
                }
                $counts[$table->tablename] = DB::table($table->tablename)->count();
            } catch (QueryException) {
                // Skip tables that can't be counted
            }
        }

        return $counts;
    }
}
