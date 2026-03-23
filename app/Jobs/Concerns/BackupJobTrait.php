<?php

declare(strict_types=1);

namespace App\Jobs\Concerns;

use App\Enums\BackupStatus;
use App\Jobs\NotifyBackupFailed;
use App\Models\Backup;
use App\Models\StorageDestination;
use App\Services\ActivityLogger;
use App\Services\CircuitBreakerService;
use App\Services\JobTracker;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

trait BackupJobTrait
{
    abstract protected function backupTypeLabel(): string;

    public static function releaseUniqueLock(int $siteId): void
    {
        $cacheKey = 'laravel_unique_job:'.static::class.':backup-'.$siteId;
        Cache::forget($cacheKey);
    }

    protected function checkCancelled(): void
    {
        if (! $this->backupId) {
            return;
        }
        $status = Backup::where('id', $this->backupId)->value('status');
        if ($status === BackupStatus::Cancelled || $status === 'cancelled') {
            Log::info("{$this->backupTypeLabel()} {$this->backupId}: cancelled by user, aborting job");
            throw new \RuntimeException('Backup cancelled by user');
        }
    }

    protected function handleFailure(\Exception $e): void
    {
        $label = $this->backupTypeLabel();

        Log::error("{$label} failed for site {$this->site->id} ({$this->site->domain})", [
            'backup_id' => $this->backupId,
            'trigger' => $this->trigger,
            'exception' => get_class($e),
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile().':'.$e->getLine(),
        ]);

        if ($this->backup) {
            $this->backup->refresh();
            if ($this->backup->status === BackupStatus::Cancelled) {
                return;
            }
            $this->backup->update([
                'status' => BackupStatus::Failed,
                'stage' => 'failed',
                'progress_message' => "{$label} failed: ".Str::limit($e->getMessage(), 200),
                'error_message' => $e->getMessage(),
                'completed_at' => now(),
                'duration_seconds' => (int) $this->backup->started_at->diffInSeconds(now()),
            ]);
        }

        $config = $this->site->backupConfig;
        if ($config) {
            $config->update(['last_backup_status' => 'failed']);
        }

        $this->site->update(['backup_ok' => false]);

        if ($this->backup) {
            NotifyBackupFailed::dispatch($this->site, $this->backup, $e->getMessage());
        }

        ActivityLogger::backupFailed($this->site, $e->getMessage());

        if ($this->attempts() >= $this->tries) {
            static::releaseUniqueLock($this->site->id);
        }
    }

    protected function reportProgress(string $stage, int $percent, string $message): void
    {
        if ($this->backup) {
            $this->backup->update([
                'stage' => $stage,
                'progress_percent' => $percent,
                'progress_message' => $message,
            ]);
        }

        JobTracker::progress($this->uniqueId(), $percent, $message);
    }

    protected function resolveStorageDestination(): ?StorageDestination
    {
        if ($this->storageDestinationId) {
            return StorageDestination::find($this->storageDestinationId);
        }

        $config = $this->site->backupConfig;
        if ($config?->storage_destination_id) {
            return StorageDestination::find($config->storage_destination_id);
        }

        return StorageDestination::where('is_default', true)
            ->where('is_active', true)
            ->first()
            ?? StorageDestination::where('is_active', true)->first();
    }

    protected function cleanup(): void
    {
        if ($this->tempDir && is_dir($this->tempDir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tempDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->tempDir);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $label = $this->backupTypeLabel();

        Log::error("{$label} job permanently failed for site {$this->site->id} ({$this->site->domain})", [
            'backup_id' => $this->backupId,
            'exception' => $exception ? get_class($exception) : 'Unknown',
            'message' => $exception?->getMessage(),
        ]);

        $exceptionClass = $exception ? get_class($exception) : 'Unknown';

        $backup = $this->backupId ? Backup::find($this->backupId) : null;
        if ($backup && ! in_array($backup->status, [BackupStatus::Completed, BackupStatus::Failed, BackupStatus::Cancelled])) {
            $backup->update([
                'status' => BackupStatus::Failed,
                'stage' => 'failed',
                'progress_message' => "{$label} failed: ".Str::limit($exception?->getMessage() ?? 'Unknown error', 200),
                'error_message' => $exception?->getMessage() ?? 'Job exceeded maximum attempts or timed out',
                'completed_at' => now(),
                'duration_seconds' => $backup->started_at ? (int) $backup->started_at->diffInSeconds(now()) : null,
            ]);
        }

        CircuitBreakerService::recordFailure($this->site, "{$label} failed: {$exceptionClass}");
        JobTracker::fail($this->uniqueId(), "{$label} failed: {$exceptionClass}");

        static::releaseUniqueLock($this->site->id);
    }
}
