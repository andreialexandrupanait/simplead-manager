<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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

        $filename = "db_dump_" . now()->format('Y-m-d_His') . ".sql.gz";
        $dumpDir = storage_path('app/db-dumps');

        if (! is_dir($dumpDir)) {
            mkdir($dumpDir, 0755, true);
        }

        $filepath = "{$dumpDir}/{$filename}";

        putenv("PGPASSWORD={$password}");

        $command = sprintf(
            'pg_dump -h %s -p %s -U %s %s | gzip > %s',
            escapeshellarg($host),
            escapeshellarg($port),
            escapeshellarg($username),
            escapeshellarg($database),
            escapeshellarg($filepath),
        );

        $this->info("Running pg_dump for {$database}...");

        exec($command . ' 2>&1', $output, $exitCode);

        putenv('PGPASSWORD');

        if ($exitCode !== 0) {
            $error = implode("\n", $output);
            $this->error("pg_dump failed (exit code {$exitCode}): {$error}");
            Log::error("Database dump failed", ['exit_code' => $exitCode, 'output' => $error]);
            return self::FAILURE;
        }

        $size = filesize($filepath);
        $sizeMb = round($size / 1024 / 1024, 2);

        $this->info("Dump created: {$filename} ({$sizeMb} MB)");
        Log::info("Database dump created: {$filename}", ['size_bytes' => $size]);

        // Retention: remove old dumps
        $keep = (int) $this->option('keep');
        $this->cleanup($dumpDir, $keep);

        return self::SUCCESS;
    }

    protected function cleanup(string $dir, int $keep): void
    {
        $files = glob("{$dir}/db_dump_*.sql.gz");
        rsort($files); // newest first

        $toDelete = array_slice($files, $keep);

        foreach ($toDelete as $file) {
            unlink($file);
            $this->line("Removed old dump: " . basename($file));
        }

        if (count($toDelete) > 0) {
            $this->info("Cleaned up " . count($toDelete) . " old dump(s), keeping {$keep}.");
        }
    }
}
