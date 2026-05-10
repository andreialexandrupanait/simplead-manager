<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Backup;
use App\Services\Backup\BackupManifestV3;
use App\Services\Backup\IntegrityVerifier;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Level B verification: pulls a sample of recent backups from their actual storage
 * destination and re-runs IntegrityVerifier on the downloaded copy. Catches drift
 * between "we built it OK" (Level A) and "it still exists and is intact in storage"
 * — accidental deletion, storage-side corruption, expired tokens that 200-OK with
 * empty body, etc.
 *
 * Selection prefers:
 * - backups never verified (verified_at IS NULL)
 * - then oldest verified_at first (re-verify the staleest)
 * Within the last 30 days only (older backups are due for retention anyway).
 */
class VerifyBackupRestoreCommand extends Command
{
    protected $signature = 'backup:verify-restore '
        .'{--count=3 : Number of backups to verify in this run} '
        .'{--max-age-days=30 : Only consider backups created within this many days}';

    protected $description = 'Sample recent backups, download from storage, validate integrity end-to-end';

    public function handle(): int
    {
        $count = max(1, (int) $this->option('count'));
        $maxAgeDays = max(1, (int) $this->option('max-age-days'));

        $candidates = Backup::query()
            ->where('status', 'completed')
            ->whereNotNull('file_path')
            ->whereNotNull('storage_destination_id')
            ->where('created_at', '>=', now()->subDays($maxAgeDays))
            ->orderByRaw('verified_at IS NULL DESC')
            ->orderBy('verified_at', 'asc')
            ->limit($count)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info('No candidate backups to verify.');

            return self::SUCCESS;
        }

        $this->info("Verifying {$candidates->count()} backup(s)...");

        $passed = 0;
        $failed = 0;
        $verifier = app(IntegrityVerifier::class);
        $tempRoot = storage_path('app/temp');

        foreach ($candidates as $backup) {
            $tempDir = $tempRoot.'/verify-restore-'.uniqid();
            @mkdir($tempDir, 0700, true);

            try {
                if ($backup->format === BackupManifestV3::FORMAT) {
                    $this->line("  #{$backup->id} (multipart prefix {$backup->file_path}) — verifying...");
                    $result = $verifier->verifyMultipart($backup, $tempDir);
                } else {
                    $localPath = $tempDir.'/'.$backup->file_name;
                    $destination = $backup->storageDestination;
                    if (! $destination) {
                        $this->markFailed($backup, 'storage destination missing');
                        $failed++;

                        continue;
                    }

                    $this->line("  #{$backup->id} ({$backup->file_name}) — downloading from {$destination->type}...");
                    $driver = StorageFactory::make($destination);
                    $driver->download($backup->file_path, $localPath);

                    if (! is_file($localPath) || filesize($localPath) === 0) {
                        $this->markFailed($backup, 'downloaded file empty or missing');
                        $failed++;

                        continue;
                    }

                    $result = $verifier->verifyArchive($localPath, $backup->checksum);
                }

                if ($result['ok']) {
                    $backup->update([
                        'verified_at' => now(),
                        'verification_status' => 'passed',
                        'verification_message' => $result['message'].' (Level B re-verified)',
                    ]);
                    $this->info("  #{$backup->id}: PASS");
                    $passed++;
                } else {
                    $this->markFailed($backup, $result['message']);
                    $this->error("  #{$backup->id}: FAIL — {$result['message']}");
                    $failed++;
                }
            } catch (\Throwable $e) {
                $this->markFailed($backup, 'verify exception: '.$e->getMessage());
                $this->error("  #{$backup->id}: EXCEPTION — {$e->getMessage()}");
                Log::warning("VerifyBackupRestore: backup #{$backup->id} threw: {$e->getMessage()}", [
                    'exception' => $e::class,
                ]);
                $failed++;
            } finally {
                $this->cleanup($tempDir);
            }
        }

        $this->newLine();
        $this->info("Result: {$passed} passed, {$failed} failed (out of {$candidates->count()})");

        // Alert if more than 1/3 of the sample failed — likely a systemic issue
        if ($failed > 0 && $failed >= max(1, intdiv($candidates->count(), 3))) {
            NotificationService::notifyAppEvent(
                event: 'backup_verify_failures',
                title: 'Backup verification failing',
                message: "Level B verification: {$failed}/{$candidates->count()} sampled backups failed integrity check. Inspect immediately — restore reliability is at risk.",
                severity: 'critical',
            );
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function markFailed(Backup $backup, string $message): void
    {
        $backup->update([
            'verified_at' => now(),
            'verification_status' => 'failed',
            'verification_message' => substr($message, 0, 1000),
        ]);
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
