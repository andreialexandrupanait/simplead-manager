<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\StorageDestination;
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
    /** Current single-file backup format written by the v3-zip pipeline. */
    private const FORMAT_V3_ZIP = 'v3-zip';

    public function __construct(private readonly IntegrityVerifier $verifier) {}

    /**
     * @return array{ok: bool, message: string}
     */
    public function verify(Backup $backup): array
    {
        $tempDir = storage_path('app/temp/verify-ondemand-'.uniqid());
        @mkdir($tempDir, 0700, true);

        try {
            $result = $this->runIntegrityCheck($backup, $tempDir);

            if (! $result['ok']) {
                return $this->fail($backup, $result['message']);
            }

            $backup->update([
                'verified_at' => now(),
                'verification_status' => 'passed',
                'verification_message' => $result['message'].' (on-demand restore test)',
            ]);

            return ['ok' => true, 'message' => $result['message']];
        } catch (\Throwable $e) {
            return $this->fail($backup, 'verify exception: '.$e->getMessage());
        } finally {
            $this->cleanup($tempDir);
        }
    }

    /**
     * Download a backup (with replica fallback) and run the SAME full integrity
     * verifier the creation path uses — closing the gap where v3-zip Level-B /
     * on-demand verification fell through to the legacy `verifyArchive`, which
     * skipped the has-files assertion (P2-33):
     *   - multipart-v3  → verifyMultipart (already replica-aware)
     *   - v3-zip        → verifyV3Zip (includes the has-files assertion)
     *   - legacy v1/v2  → verifyArchive (restore-read compatibility)
     *
     * Does NOT persist any state — callers own the record update / alerting.
     *
     * @return array{ok: bool, message: string}
     */
    public function runIntegrityCheck(Backup $backup, string $tempDir): array
    {
        if ($backup->format === BackupManifestV3::FORMAT) {
            $result = $this->verifier->verifyMultipart($backup, $tempDir);

            return ['ok' => $result['ok'], 'message' => $result['message']];
        }

        $localPath = $tempDir.'/'.($backup->file_name ?: 'backup.zip');
        $download = $this->downloadWithReplicaFallback($backup, $localPath);
        if (! $download['ok']) {
            return $download;
        }

        if (! is_file($localPath) || filesize($localPath) === 0) {
            return ['ok' => false, 'message' => 'downloaded file empty or missing'];
        }

        $checksum = (string) $backup->checksum;
        $result = $backup->format === self::FORMAT_V3_ZIP
            ? $this->verifier->verifyV3Zip($localPath, $checksum)
            : $this->verifier->verifyArchive($localPath, $checksum);

        return ['ok' => $result['ok'], 'message' => $result['message']];
    }

    /**
     * Download a single-file backup from its primary storage, falling back to
     * each replicated copy in turn if the primary is unreachable — an
     * unreachable PRIMARY must not false-alarm a healthy REPLICATED backup
     * (P2-33). Returns ok=false only when every destination fails.
     *
     * @return array{ok: bool, message: string}
     */
    private function downloadWithReplicaFallback(Backup $backup, string $localPath): array
    {
        $candidates = [];
        if ($backup->storage_destination_id) {
            $candidates[(int) $backup->storage_destination_id] = $backup->file_path;
        }
        foreach ($backup->replicas ?? [] as $replica) {
            if (! empty($replica['destination_id'])) {
                $destId = (int) $replica['destination_id'];
                $candidates[$destId] ??= ($replica['remote_path'] ?? $backup->file_path);
            }
        }

        if ($candidates === []) {
            return ['ok' => false, 'message' => 'no storage destination or replicas to verify against'];
        }

        $errors = [];
        foreach ($candidates as $destId => $remotePath) {
            $destination = StorageDestination::find($destId);
            if (! $destination) {
                $errors[] = "destination #{$destId} not found";

                continue;
            }

            try {
                StorageFactory::make($destination)->download((string) $remotePath, $localPath);
                if (is_file($localPath) && filesize($localPath) > 0) {
                    return ['ok' => true, 'message' => "downloaded from {$destination->name}"];
                }
                $errors[] = "{$destination->name}: downloaded file empty";
            } catch (\Throwable $e) {
                @unlink($localPath);
                $errors[] = "{$destination->name}: {$e->getMessage()}";
            }
        }

        return ['ok' => false, 'message' => 'all storage destinations unreachable ('.implode('; ', $errors).')'];
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
