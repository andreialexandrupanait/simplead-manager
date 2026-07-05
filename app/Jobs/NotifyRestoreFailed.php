<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Backup;
use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class NotifyRestoreFailed implements ShouldQueue
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

        $safetyBackup = Backup::where('site_id', $this->site->id)
            ->where('trigger', 'pre_restore')
            ->whereNotNull('completed_at')
            ->latest('completed_at')
            ->first();

        $safetyNote = $safetyBackup
            ? "Pre-restore safety backup: #{$safetyBackup->id} ({$safetyBackup->completed_at->diffForHumans()})."
            : 'No pre-restore safety backup found.';

        $summary = "\xF0\x9F\x94\xA5 Restore FAILED · *{$this->site->name}* — the site may be in an INCONSISTENT state, verify it immediately. `{$error}` {$safetyNote}";
        $deepLink = '<'.route('sites.backups', $this->site).'|Open backups →>';

        NotificationService::notifySiteEventSlim(
            site: $this->site,
            event: 'restore_failed',
            summary: $summary,
            deepLink: $deepLink,
            severity: 'critical',
            webhookPayload: [
                'backup' => [
                    'id' => $this->backup->id,
                    'type' => $this->backup->type,
                ],
                'error' => $this->errorMessage,
                'safety_backup_id' => $safetyBackup?->id,
            ],
        );
    }
}
