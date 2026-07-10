<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Backup;
use App\Services\Backup\Storage\StorageFactory;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Restore-verification for a single backup: pull it from its real storage,
 * open the archive, extract + parse the DB dump and check the file structure
 * (via IntegrityVerifier) — i.e. prove the backup is restorable without applying
 * it to a live site. Records the result on the backup and alerts on failure.
 */
class BackupVerifier
{
    public function __construct(private readonly IntegrityVerifier $verifier) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function verify(Backup $backup): array
    {
        $tempDir = storage_path('app/temp/verify-ondemand-'.uniqid());
        @mkdir($tempDir, 0700, true);

        try {
            if ($backup->format === BackupManifestV3::FORMAT) {
                $result = $this->verifier->verifyMultipart($backup, $tempDir);
            } else {
                $destination = $backup->storageDestination;
                if (! $destination) {
                    return $this->fail($backup, 'storage destination missing');
                }

                $localPath = $tempDir.'/'.$backup->file_name;
                StorageFactory::make($destination)->download($backup->file_path, $localPath);

                if (! is_file($localPath) || filesize($localPath) === 0) {
                    return $this->fail($backup, 'downloaded file empty or missing');
                }

                $result = $this->verifier->verifyArchive($localPath, $backup->checksum);
            }

            if (! $result['ok']) {
                return $this->fail($backup, $result['message'] ?? 'verification failed');
            }

            $backup->update([
                'verified_at' => now(),
                'verification_status' => 'passed',
                'verification_message' => ($result['message'] ?? 'ok').' (on-demand restore test)',
            ]);

            return ['ok' => true, 'message' => $result['message'] ?? 'ok'];
        } catch (\Throwable $e) {
            return $this->fail($backup, 'verify exception: '.$e->getMessage());
        } finally {
            $this->cleanup($tempDir);
        }
    }

    /**
     * @return array{ok: false, message: string}
     */
    private function fail(Backup $backup, string $message): array
    {
        $backup->update([
            'verified_at' => now(),
            'verification_status' => 'failed',
            'verification_message' => substr($message, 0, 1000),
        ]);

        /** @var \App\Models\Site|null $site */
        $site = $backup->site;
        if ($site) {
            NotificationService::notifySiteEventSlim(
                site: $site,
                event: 'backup_verify_failures',
                summary: "\xE2\x9A\xA0\xEF\xB8\x8F Backup #{$backup->id} for *{$site->name}* failed restore verification: {$message}",
                severity: 'critical',
            );
        }

        Log::warning("Backup {$backup->id} failed restore verification: {$message}");

        return ['ok' => false, 'message' => $message];
    }

    private function cleanup(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
