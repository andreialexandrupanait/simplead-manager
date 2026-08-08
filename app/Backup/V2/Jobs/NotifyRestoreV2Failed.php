<?php

declare(strict_types=1);

namespace App\Backup\V2\Jobs;

use App\Backup\V2\Models\RestoreSession;
use App\Models\Site;
use App\Services\Notifications\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Tell someone a V2 restore failed.
 *
 * A failed backup has alerted since the engine learned to record its own
 * failures. A failed restore alerted nobody: RestoreSession::transitionTo wrote
 * a warning to the log and stopped there, and nothing reads that log unless
 * somebody already suspects something.
 *
 * That is the wrong way round. A backup that fails tonight is a backup you take
 * again tomorrow. A restore that fails is somebody standing in front of a broken
 * site, in the one moment where not being told is most expensive — and the engine
 * rolls back, so the site is likely fine, which makes silence even more
 * plausible and even more misleading.
 *
 * Severity is critical for the same reason it is on the backup alert: this is
 * not "retry later". Somebody asked for a site to be put back and it was not.
 */
class NotifyRestoreV2Failed implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 30;

    /** @var array<int, int> */
    public array $backoff = [30, 60, 120];

    public function __construct(public readonly int $restoreSessionId)
    {
        $this->onQueue('notifications');
    }

    public function handle(): void
    {
        $session = RestoreSession::find($this->restoreSessionId);
        if (! $session instanceof RestoreSession) {
            return;
        }

        $site = $session->site;
        if (! $site instanceof Site) {
            return;
        }

        $reason = $session->error_message ?: 'no error message recorded';
        $phase = $session->stage ?: $session->state->value;

        $summary = sprintf(
            '*%s* — restore failed at *%s* (%s, restore #%d): %s',
            $site->name,
            $phase,
            (string) $session->mode,
            $session->id,
            \Illuminate\Support\Str::limit($reason, 300),
        );

        NotificationService::notifySiteEventSlim(
            site: $site,
            event: 'restore_failed',
            summary: $summary,
            // The site's own backup page, not the V2 console — that console is
            // behind a flag and an admin check, so for most recipients the link
            // would 404 on the alert that matters most.
            deepLink: '<'.route('sites.backups', $site).'|Open the site backups →>',
            severity: 'critical',
        );
    }
}
