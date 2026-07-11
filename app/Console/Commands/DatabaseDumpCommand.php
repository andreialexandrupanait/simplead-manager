<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class DatabaseDumpCommand extends Command
{
    protected $signature = 'db:dump
        {--keep=7 : Number of daily dumps to retain}';

    protected $description = 'Create a compressed PostgreSQL dump as an independent database backup';

    /**
     * Minimum acceptable size (bytes) for a compressed dump artifact.
     * A gzip stream carrying a real pg_dump (even of a near-empty schema) is
     * comfortably above this; anything smaller signals a truncated/empty dump.
     */
    private const MIN_ARTIFACT_BYTES = 100;

    public function handle(): int
    {
        $host = config('database.connections.pgsql.host');
        $port = config('database.connections.pgsql.port', 5432);
        $database = config('database.connections.pgsql.database');
        $username = config('database.connections.pgsql.username');
        $password = config('database.connections.pgsql.password');

        $stamp = now()->format('Y-m-d_His');
        $filename = "db_dump_{$stamp}.sql.gz";
        $dumpDir = storage_path('app/db-dumps');

        if (! is_dir($dumpDir)) {
            mkdir($dumpDir, 0755, true);
        }

        $rawPath = "{$dumpDir}/db_dump_{$stamp}.sql";
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

        $this->info("Running pg_dump for {$database}...");

        // ── Step 1: dump to a plain .sql file ───────────────────────────
        // Dumping straight to a file (not piping into gzip) lets us observe
        // pg_dump's own exit code. Piping through gzip would surface only
        // gzip's exit code, masking a failed dump as success.
        $dumpCommand = sprintf(
            'PGPASSFILE=%s pg_dump -h %s -p %s -U %s %s -f %s',
            escapeshellarg($pgpassPath),
            escapeshellarg((string) $host),
            escapeshellarg((string) $port),
            escapeshellarg((string) $username),
            escapeshellarg((string) $database),
            escapeshellarg($rawPath),
        );

        exec($dumpCommand.' 2>&1', $dumpOutput, $dumpExit);
        @unlink($pgpassPath);

        if ($dumpExit !== 0) {
            return $this->failDump(
                "pg_dump failed (exit code {$dumpExit})",
                implode("\n", $dumpOutput),
                [$rawPath],
            );
        }

        // Verify the dump is a real, non-empty pg_dump artifact.
        clearstatcache(true, $rawPath);
        if (! is_file($rawPath) || filesize($rawPath) === 0) {
            return $this->failDump(
                'pg_dump reported success but produced no output',
                'Empty or missing dump file: '.$rawPath,
                [$rawPath],
            );
        }

        if (! $this->looksLikePgDump($rawPath)) {
            return $this->failDump(
                'pg_dump output failed sanity check',
                'Dump file does not contain the expected PostgreSQL dump header: '.$rawPath,
                [$rawPath],
            );
        }

        // ── Step 2: gzip the verified dump ──────────────────────────────
        // stderr is captured separately (never redirected into the artifact)
        // so a gzip warning can't corrupt the .sql.gz payload.
        $gzipCommand = sprintf(
            'gzip -c %s > %s',
            escapeshellarg($rawPath),
            escapeshellarg($filepath),
        );

        exec($gzipCommand, $gzipOutput, $gzipExit);

        if ($gzipExit !== 0) {
            return $this->failDump(
                "gzip failed (exit code {$gzipExit})",
                implode("\n", $gzipOutput),
                [$rawPath, $filepath],
            );
        }

        // ── Step 3: integrity + size assertions ─────────────────────────
        exec(sprintf('gunzip -t %s 2>&1', escapeshellarg($filepath)), $testOutput, $testExit);
        if ($testExit !== 0) {
            return $this->failDump(
                'Compressed dump failed gunzip integrity check',
                implode("\n", $testOutput),
                [$rawPath, $filepath],
            );
        }

        clearstatcache(true, $filepath);
        $size = is_file($filepath) ? (int) filesize($filepath) : 0;
        if ($size < self::MIN_ARTIFACT_BYTES) {
            return $this->failDump(
                'Compressed dump is smaller than the minimum acceptable size',
                "Artifact size {$size} bytes is below the ".self::MIN_ARTIFACT_BYTES.'-byte floor',
                [$rawPath, $filepath],
            );
        }

        // Raw dump verified and compressed successfully — drop the plaintext copy.
        @unlink($rawPath);

        // ── Step 4: optional encryption ─────────────────────────────────
        $encryptionKey = config('app.backup_encryption_key');
        if ($encryptionKey) {
            $encryptedPath = $filepath.'.enc';
            $encCommand = sprintf(
                'openssl enc -aes-256-cbc -salt -pbkdf2 -in %s -out %s -pass pass:%s 2>&1',
                escapeshellarg($filepath),
                escapeshellarg($encryptedPath),
                escapeshellarg((string) $encryptionKey)
            );

            exec($encCommand, $encOutput, $encExit);

            if ($encExit !== 0) {
                return $this->failDump(
                    'Database dump encryption failed',
                    implode("\n", $encOutput),
                    [$filepath, $encryptedPath],
                );
            }

            unlink($filepath);
            $filepath = $encryptedPath;
            $filename .= '.enc';
            $size = (int) filesize($filepath);
            $this->info('Dump encrypted with AES-256-CBC.');
        }

        $sizeMb = round($size / 1024 / 1024, 2);

        $this->info("Dump created: {$filename} ({$sizeMb} MB)");
        Log::info("Database dump created: {$filename}", ['size_bytes' => $size, 'encrypted' => (bool) $encryptionKey]);

        // Retention: remove old dumps
        $keep = (int) $this->option('keep');
        $this->cleanup($dumpDir, $keep);

        return self::SUCCESS;
    }

    /**
     * Confirm the raw dump begins with the standard pg_dump header so we never
     * accept a truncated file or an error page written to the output path.
     */
    private function looksLikePgDump(string $path): bool
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }

        $head = (string) fread($handle, 512);
        fclose($handle);

        return str_contains($head, 'PostgreSQL database dump');
    }

    /**
     * Report a dump failure: log it, fire a critical platform notification, and
     * clean up any partial artifacts. Returns the command FAILURE code so the
     * failed dump is never silently reported as "successful".
     *
     * @param  list<string>  $cleanup
     */
    private function failDump(string $reason, string $detail, array $cleanup = []): int
    {
        foreach ($cleanup as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        $this->error("{$reason}: {$detail}");
        Log::error('Database dump failed', ['reason' => $reason, 'detail' => $detail]);

        NotificationService::notifyAppEvent(
            event: 'db_dump_failed',
            title: 'Database Dump Failed',
            message: "The nightly database dump failed: {$reason}. The platform may be without a fresh independent backup.",
            fields: [
                'Reason' => $reason,
                'Detail' => mb_substr($detail, 0, 500),
            ],
            severity: 'critical',
            sync: true,
        );

        return self::FAILURE;
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
