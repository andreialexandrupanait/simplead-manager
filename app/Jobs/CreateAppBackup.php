<?php

namespace App\Jobs;

use App\Services\ActivityLogger;
use App\Services\AppBackup\AppBackupService;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class CreateAppBackup implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;
    public int $tries = 1;

    public function __construct(
        public string $type = 'full',
        public string $trigger = 'manual',
        public ?int $storageDestinationId = null,
        public array $options = [],
        public ?string $notes = null,
    ) {
        $this->onQueue('backups');
    }

    public function handle(AppBackupService $service): void
    {
        $service->createBackup(
            $this->type,
            $this->trigger,
            $this->storageDestinationId,
            $this->options,
            $this->notes,
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('CreateAppBackup job failed: ' . $exception->getMessage());

        ActivityLogger::appBackupFailed($exception->getMessage());

        NotificationService::notifyAppEvent(
            'app_backup_failed',
            'Application Backup Failed',
            'Application backup job failed: ' . Str::limit($exception->getMessage(), 200),
            ['Type' => $this->type, 'Error' => Str::limit($exception->getMessage(), 200)],
            'critical',
        );
    }
}
