<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DatabaseDumpCommand extends Command
{
    protected $signature = 'db:dump
        {--keep=7 : Number of daily dumps to retain}';

    protected $description = 'Create a compressed PostgreSQL dump as an independent database backup';

    public function handle(): int
    {
        $host = config('database.connections.pgsql.host');
        $port = config('database.connections.pgsql.port', 5432);
        $database = config('database.connections.pgsql.database');
        $username = config('database.connections.pgsql.username');
        $password = config('database.connections.pgsql.password');

        $filename = 'db_dump_'.now()->format('Y-m-d_His').'.sql.gz';
        $dumpDir = storage_path('app/db-dumps');

        if (! is_dir($dumpDir)) {
            mkdir($dumpDir, 0755, true);
        }

        $filepath = "{$dumpDir}/{$filename}";

        // Use a temporary .pgpass file instead of putenv() to avoid
        // exposing the password via /proc/[pid]/environ
        $pgpassPath = tempnam(sys_get_temp_dir(), 'pgpass_');
        file_put_contents($pgpassPath, sprintf(
            "%s:%s:%s:%s:%s\n",
            $host,
            $port,
            $database,
            $username,
            str_replace(['\\', ':'], ['\\\\', '\\:'], $password),
        ));
        chmod($pgpassPath, 0600);

        $command = sprintf(
            'PGPASSFILE=%s pg_dump -h %s -p %s -U %s %s | gzip > %s',
            escapeshellarg($pgpassPath),
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($filepath),
        );

        $this->info("Running pg_dump for {$database}...");

        exec($command.' 2>&1', $output, $exitCode);

        @unlink($pgpassPath);

        if ($exitCode !== 0) {
            $error = implode("\n", $output);
            $this->error("pg_dump failed (exit code {$exitCode}): {$error}");
            Log::error('Database dump failed', ['exit_code' => $exitCode, 'output' => $error]);

            return self::FAILURE;
        }

        // Encrypt if BACKUP_ENCRYPTION_KEY is set
        $encryptionKey = config('app.backup_encryption_key');
        if ($encryptionKey) {
            $encryptedPath = $filepath.'.enc';
            $encCommand = sprintf(
                'openssl enc -aes-256-cbc -salt -pbkdf2 -in %s -out %s -pass pass:%s 2>&1',
                escapeshellarg($filepath),
                escapeshellarg($encryptedPath),
                escapeshellarg($encryptionKey)
            );

            exec($encCommand, $encOutput, $encExit);

            if ($encExit !== 0) {
                $this->error('Encryption failed: '.implode("\n", $encOutput));
                Log::error('Database dump encryption failed', ['output' => $encOutput]);
                unlink($filepath);

                return self::FAILURE;
            }

            unlink($filepath);
            $filepath = $encryptedPath;
            $filename .= '.enc';
            $this->info('Dump encrypted with AES-256-CBC.');
        }

        $size = filesize($filepath);
        $sizeMb = round($size / 1024 / 1024, 2);

        $this->info("Dump created: {$filename} ({$sizeMb} MB)");
        Log::info("Database dump created: {$filename}", ['size_bytes' => $size, 'encrypted' => (bool) $encryptionKey]);

        // Retention: remove old dumps
        $keep = (int) $this->option('keep');
        $this->cleanup($dumpDir, $keep);

        return self::SUCCESS;
    }

    protected function cleanup(string $dir, int $keep): void
    {
        $files = array_merge(
            glob("{$dir}/db_dump_*.sql.gz") ?: [],
            glob("{$dir}/db_dump_*.sql.gz.enc") ?: []
        );
        rsort($files); // newest first

        $toDelete = array_slice($files, $keep);

        foreach ($toDelete as $file) {
            unlink($file);
            $this->line('Removed old dump: '.basename($file));
        }

        if (count($toDelete) > 0) {
            $this->info('Cleaned up '.count($toDelete)." old dump(s), keeping {$keep}.");
        }
    }
}
