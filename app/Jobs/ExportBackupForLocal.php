<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Services\Backup\LocalFlywheelRepackager;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Repackage a completed v3-zip backup into the layout Local by Flywheel expects
 * on import. The output is stored alongside the source backup on the same
 * StorageDestination, with `-local.zip` suffix. Surfaces state on the Backup
 * model via the local_export_* columns; the SiteBackups Livewire poll picks
 * up the status without further wiring.
 */
class ExportBackupForLocal implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 2;

    public int $uniqueFor = 1800;

    public function __construct(public int $backupId) {}

    public function uniqueId(): string
    {
        return 'local-export-'.$this->backupId;
    }

    public function handle(): void
    {
        /** @var Backup|null $backup */
        $backup = Backup::with('storageDestination', 'site')->find($this->backupId);
        if (! $backup) {
            Log::warning('ExportBackupForLocal: backup not found', ['backup_id' => $this->backupId]);

            return;
        }

        if ($backup->status !== BackupStatus::Completed) {
            Log::warning('ExportBackupForLocal: backup not completed', [
                'backup_id' => $this->backupId,
                'status' => $backup->status->value,
            ]);

            return;
        }

        $destination = $backup->storageDestination;
        if (! $destination || ! $backup->file_path) {
            $this->markFailed($backup, 'Backup has no storage destination or file path.');

            return;
        }

        $backup->update([
            'local_export_status' => 'processing',
            'local_export_error' => null,
        ]);

        $tempDir = storage_path('app/temp/local-export-'.uniqid());
        if (! mkdir($tempDir, 0700, true) && ! is_dir($tempDir)) {
            $this->markFailed($backup, "Cannot create temp directory: {$tempDir}");

            return;
        }

        $sourcePath = $tempDir.'/source.zip';
        $outputName = $this->buildOutputFileName($backup);
        $outputPath = $tempDir.'/'.$outputName;
        $remotePath = $this->buildRemotePath($backup->file_path, $outputName);

        try {
            $driver = StorageFactory::make($destination);

            Log::info('ExportBackupForLocal: downloading source', [
                'backup_id' => $backup->id,
                'remote' => $backup->file_path,
            ]);
            $driver->download($backup->file_path, $sourcePath);

            Log::info('ExportBackupForLocal: repackaging', [
                'backup_id' => $backup->id,
                'output' => $outputName,
            ]);
            $repackager = new LocalFlywheelRepackager($sourcePath, $outputPath);
            $result = $repackager->repackage();

            @unlink($sourcePath);

            Log::info('ExportBackupForLocal: uploading', [
                'backup_id' => $backup->id,
                'size' => $result['size'],
                'entries' => $result['entries'],
                'remote' => $remotePath,
            ]);
            $driver->upload($outputPath, $remotePath);

            $backup->update([
                'local_export_status' => 'completed',
                'local_export_file_path' => $remotePath,
                'local_export_file_size' => $result['size'],
                'local_export_error' => null,
                'local_exported_at' => now(),
            ]);

            Log::info('ExportBackupForLocal: completed', [
                'backup_id' => $backup->id,
                'remote' => $remotePath,
            ]);
        } catch (Throwable $e) {
            Log::error('ExportBackupForLocal: failed', [
                'backup_id' => $backup->id,
                'error' => $e->getMessage(),
            ]);
            $this->markFailed($backup, $e->getMessage());
            throw $e;
        } finally {
            $this->cleanupTemp($tempDir);
        }
    }

    public function failed(Throwable $exception): void
    {
        $backup = Backup::find($this->backupId);
        if ($backup) {
            $this->markFailed($backup, $exception->getMessage());
        }
    }

    private function markFailed(Backup $backup, string $message): void
    {
        $backup->update([
            'local_export_status' => 'failed',
            'local_export_error' => substr($message, 0, 1000),
        ]);
    }

    private function buildOutputFileName(Backup $backup): string
    {
        $base = $backup->file_name ?: ($backup->site?->domain.'-backup.zip');
        if (str_ends_with($base, '.zip')) {
            $base = substr($base, 0, -4);
        }

        return $base.'-local.zip';
    }

    private function buildRemotePath(string $sourceFilePath, string $outputName): string
    {
        $dir = dirname($sourceFilePath);
        $dir = ($dir === '.' || $dir === '') ? '' : rtrim($dir, '/').'/';

        return $dir.$outputName;
    }

    private function cleanupTemp(string $tempDir): void
    {
        if (! is_dir($tempDir)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($tempDir);
    }
}
