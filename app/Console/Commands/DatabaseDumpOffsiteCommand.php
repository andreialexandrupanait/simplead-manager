<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Push the latest local PostgreSQL dump off-host so the platform's own daily
 * database backup survives loss of the app host.
 *
 * This is deliberately a SEPARATE command from db:dump (which owns dump
 * creation + exit-code handling): it only adds the off-site push + verification
 * so it never conflicts with dump-creation work. If no remote destination is
 * configured, or the push cannot be verified, it fires a critical notification
 * rather than silently leaving the dump local-only.
 */
class DatabaseDumpOffsiteCommand extends Command
{
    protected $signature = 'db:dump-offsite
        {--path= : Absolute path to a specific dump file (defaults to the newest local dump)}';

    protected $description = 'Push the latest local PostgreSQL dump to an off-site storage destination';

    public function handle(): int
    {
        $file = $this->option('path') ?: $this->latestDump();

        if (! $file || ! is_file($file)) {
            // A missing dump is a dump-creation concern (owned by db:dump), not
            // an off-site failure — do not raise a false off-site alert here.
            $this->error('No local database dump found to push off-site.');

            return self::FAILURE;
        }

        $destination = StorageDestination::where('is_active', true)
            ->orderByDesc('is_default')
            ->first();

        if (! $destination) {
            $this->error('No active storage destination — the database dump stays on the host (DEGRADED).');

            $this->alertFailure(
                'No active off-site storage destination is configured, so the daily database dump never left the host. Disaster recovery is NOT protected.',
                ['File' => basename($file), 'Location' => 'Local host only (no off-site copy)'],
            );

            return self::FAILURE;
        }

        $fileName = basename($file);
        $remotePath = 'database-dumps/'.$fileName;

        try {
            $driver = StorageFactory::make($destination);
            $dbDumpsPath = $destination->config['db_dumps_path'] ?? null;

            if ($dbDumpsPath) {
                $absoluteRemotePath = rtrim($dbDumpsPath, '/').'/'.$fileName;
                $driver->uploadToAbsolutePath($file, $absoluteRemotePath);
                $remotePath = $absoluteRemotePath;
            } else {
                $driver->upload($file, $remotePath);

                // Verify the destination actually received the file. Only run
                // for base-path uploads whose exists() semantics are consistent
                // across drivers; a failed absolute-path upload throws instead.
                if (! $driver->exists($remotePath)) {
                    throw new \RuntimeException("Remote destination did not confirm {$remotePath}.");
                }
            }

            $size = (int) filesize($file);
            $destination->increment('used_bytes', $size);

            $this->info("Pushed {$fileName} off-site to {$destination->name} ({$remotePath}).");
            Log::info('Database dump pushed off-site', [
                'file' => $fileName,
                'destination' => $destination->name,
                'remote_path' => $remotePath,
                'size_bytes' => $size,
            ]);

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error("Off-site push failed: {$e->getMessage()}");
            Log::error('Database dump off-site push failed', [
                'file' => $fileName,
                'destination' => $destination->name,
                'error' => $e->getMessage(),
            ]);

            $this->alertFailure(
                "The daily database dump could not be pushed off-site to {$destination->name}: {$e->getMessage()}. The dump remains on the host only.",
                ['File' => $fileName, 'Destination' => $destination->name, 'Error' => \Illuminate\Support\Str::limit($e->getMessage(), 200)],
            );

            return self::FAILURE;
        }
    }

    /**
     * Newest local dump by timestamped filename. Encrypted (.enc) variants sort
     * after their plaintext twin for the same timestamp, so they win.
     */
    private function latestDump(): ?string
    {
        $dir = storage_path('app/db-dumps');

        $files = array_merge(
            glob("{$dir}/db_dump_*.sql.gz") ?: [],
            glob("{$dir}/db_dump_*.sql.gz.enc") ?: [],
        );

        if ($files === []) {
            return null;
        }

        rsort($files);

        return $files[0];
    }

    private function alertFailure(string $message, array $fields): void
    {
        NotificationService::notifyAppEvent(
            'db_dump_offsite_failed',
            'Database Dump Off-site Push Failed',
            $message,
            $fields,
            'critical',
        );
    }
}
