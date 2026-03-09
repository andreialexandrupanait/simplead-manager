<?php

namespace App\Jobs;

use App\Mail\BackupAlertMail;
use App\Models\Backup;
use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyBackupFailed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 30;
    public array $backoff = [30, 60, 120];

    public function __construct(
        public Site $site,
        public Backup $backup,
        public string $errorMessage,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $title = "BACKUP FAILED: {$this->site->name}";
        $message = "A backup for {$this->site->name} has failed: {$this->errorMessage}";

        $fields = [
            ['title' => 'Site', 'value' => $this->site->name, 'short' => true],
            ['title' => 'URL', 'value' => $this->site->url, 'short' => true],
            ['title' => 'Type', 'value' => $this->backup->type, 'short' => true],
            ['title' => 'Error', 'value' => $this->errorMessage, 'short' => false],
        ];

        $webhookPayload = [
            'backup' => [
                'id' => $this->backup->id,
                'type' => $this->backup->type,
                'trigger' => $this->backup->trigger,
            ],
            'error' => $this->errorMessage,
        ];

        NotificationService::notifySiteEvent(
            site: $this->site,
            event: 'backup_failed',
            title: $title,
            message: $message,
            fields: $fields,
            severity: 'critical',
            webhookPayload: $webhookPayload,
            mailableClass: BackupAlertMail::class,
            mailableArgs: [$this->site, $this->backup, $this->errorMessage],
        );
    }
}
