<?php

declare(strict_types=1);

namespace App\Backup\V2\Jobs;

use App\Backup\V2\Models\BackupSession;
use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tell someone a V2 backup failed.
 *
 * Until the failure path existed there was nothing to tell them about: the
 * engine caught only corruption, so any other error left the session frozen
 * mid-phase with no terminal state — and a session stuck at `uploading` reads
 * exactly like one still uploading. V2 could fail in complete silence, which is
 * the single reason it could not be trusted with a site.
 *
 * Carries what §17 of the product spec asks an alert to carry: the site, the
 * session, the phase it died in, a stable error code, and a link straight to the
 * session rather than to a list.
 */
class NotifyBackupV2Failed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120];

    public function __construct(public readonly int $sessionId)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $session = BackupSession::find($this->sessionId);
        if (! $session instanceof BackupSession) {
            return;
        }

        $site = $session->site;
        if (! $site instanceof Site) {
            return;
        }

        $code = $session->error_code ?: 'unknown';
        $reason = $session->error_message ?: 'no error message recorded';
        $phase = $session->stage ?: $session->state->value;

        $summary = sprintf(
            '*%s* — backup failed at *%s* (`%s`): %s',
            $site->name,
            $phase,
            $code,
            \Illuminate\Support\Str::limit($reason, 300),
        );

        NotificationService::notifySiteEventSlim(
            site: $site,
            event: 'backup_failed',
            summary: $summary,
            deepLink: '<'.route('backup-v2.detail', $session->id).'|Open the session →>',
            // corrupt is not "try again later": the objects that were written
            // disagree with the manifest, and no retry makes that untrue.
            severity: 'critical',
        );
    }
}
