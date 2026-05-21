<?php

declare(strict_types=1);

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
        $error = str_replace(['`', "\n"], ['\'', ' '], $this->errorMessage);
        $summary = "\xF0\x9F\x92\xBE\xE2\x9D\x8C Backup failed · *{$this->site->name}* — `{$error}`";
        $deepLink = '<'.route('sites.backups', $this->site).'|Open backups →>';

        $webhookPayload = [
            'backup' => [
                'id' => $this->backup->id,
                'type' => $this->backup->type,
                'trigger' => $this->backup->trigger,
            ],
            'error' => $this->errorMessage,
        ];

        NotificationService::notifySiteEventSlim(
            site: $this->site,
            event: 'backup_failed',
            summary: $summary,
            deepLink: $deepLink,
            severity: 'critical',
            webhookPayload: $webhookPayload,
            mailableClass: BackupAlertMail::class,
            mailableArgs: [$this->site, $this->backup, $this->errorMessage],
        );
    }
}
