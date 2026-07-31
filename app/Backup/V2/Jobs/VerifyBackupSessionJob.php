<?php

declare(strict_types=1);

namespace App\Backup\V2\Jobs;

use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\BackupVerification;
use App\Backup\V2\Storage\S3ClientFactory;
use App\Backup\V2\Storage\SessionLayoutResolver;
use App\Backup\V2\Support\BackupLogger;
use App\Backup\V2\Verification\BackupVerifier;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

/**
 * Verify a completed backup because someone asked, using the same checks the
 * engine runs at completion.
 *
 * The UI's "Verify" button used to call SessionActions::markVerified(), which
 * set verified_at = now() and looked at nothing. It was a button that made a
 * claim on the operator's behalf. That claim is load-bearing:
 * BackupHealthService reads verified_at when scoring a site,
 * so a decorative stamp could make retention keep a broken backup and expire a
 * sound one.
 *
 * A job rather than a synchronous call: verification is one HEAD per object
 * plus three small GETs, which for a full backup with hundreds of chunks is
 * well past what belongs in a Livewire request. V1 does the same thing for the
 * same reason (WithBackupActions::verifyBackupNow → RunBackupVerification).
 */
class VerifyBackupSessionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $sessionId) {}

    public function uniqueId(): string
    {
        return 'verify-v2-session-'.$this->sessionId;
    }

    public function handle(): void
    {
        $session = BackupSession::find($this->sessionId);
        if (! $session instanceof BackupSession) {
            return;
        }

        $site = $session->site;
        if (! $site instanceof Site) {
            throw new RuntimeException("BackupSession {$this->sessionId} has no site.");
        }

        $logger = (new BackupLogger)->forSession('verify', $session->id, $session->site_id);

        // Same resolution as RunBackupSessionJob — the objects must be looked for
        // where they were written. Resolving them any other way is how
        // backup:v2-deep-verify ended up reporting a healthy backup as failed.
        $destination = StorageDestination::resolveForSite($site);
        if ($destination === null) {
            throw new RuntimeException("No storage destination is configured for site {$session->site_id}.");
        }

        $s3 = S3ClientFactory::forDestination($destination);

        $layout = SessionLayoutResolver::for($session, $site);

        $verification = (new BackupVerifier($logger))->verifyOnComplete(
            $session,
            $s3->client(),
            $s3->bucket(),
            $layout,
            BackupVerification::KIND_MANUAL,
        );

        $logger->log(
            $verification->status === BackupVerification::STATUS_PASSED ? 'info' : 'warning',
            'operator-requested verification finished',
            ['session_id' => $session->id, 'status' => $verification->status],
        );
    }

    /**
     * A verification that dies mid-flight must not leave the backup wearing a
     * stamp it can no longer support.
     */
    public function failed(?Throwable $e): void
    {
        $session = BackupSession::find($this->sessionId);
        if ($session instanceof BackupSession) {
            $session->verified_at = null;
            $session->save();
        }

        (new BackupLogger)->forSession('verify', $this->sessionId, null)
            ->error('verification job failed', ['error' => $e?->getMessage()]);
    }
}
