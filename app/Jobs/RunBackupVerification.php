<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\BackupVerification;
use App\Backup\V2\Storage\S3ClientFactory;
use App\Backup\V2\Storage\SessionLayoutResolver;
use App\Backup\V2\Verification\DeepVerifyService;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * On-demand restore verification of a single backup (operator "Test restore"
 * action). Runs the same restorability checks the weekly Level-B sweep does.
 */
class RunBackupVerification implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 900;

    public int $uniqueFor = 2700; // P1-07: release stale unique lock after a hard kill (≈3× timeout)

    public function __construct(public Backup $backup)
    {
        $this->onQueue('backups');
    }

    public function uniqueId(): string
    {
        return 'verify-backup-'.$this->backup->id;
    }

    public function handle(): void
    {
        $this->deepVerify();
    }

    /**
     * Actually test the backup.
     *
     * Pressing "Test restore" on a backup made by the new engine used to return a
     * pass without testing anything: the legacy verifier recognised the row as
     * not its own and answered "Verified by the backup engine at creation — not
     * re-checked by the legacy verifier." True, and the wrong answer to give. The
     * create-time verification is HEAD-only — it checks that every object exists
     * and is the declared size — so a green tick here told an operator their
     * backup had been restore-tested when nothing had been opened.
     *
     * DeepVerifyService is the check that earns the word: it pulls the bytes,
     * re-hashes them in full, opens the file chunks as zips and parses the DB
     * segments as SQL. It is what backup:v2-deep-verify runs, on demand for one
     * backup instead of a nightly sample.
     */
    private function deepVerify(): void
    {
        $session = BackupSession::where('backup_id', $this->backup->id)->first();
        $site = $this->backup->site;

        if (! $session instanceof BackupSession || ! $site instanceof Site) {
            $this->backup->update([
                'verification_status' => 'failed',
                'verification_message' => 'This backup has no session to verify against.',
                'verified_at' => now(),
            ]);

            return;
        }

        $destination = StorageDestination::resolveForSite($site);
        if ($destination === null) {
            $this->backup->update([
                'verification_status' => 'failed',
                'verification_message' => 'No storage destination is configured for this site.',
                'verified_at' => now(),
            ]);

            return;
        }

        $s3 = S3ClientFactory::forDestination($destination);

        // Resolved exactly as the runner resolved it when writing. Looking for the
        // objects anywhere else is how deep-verify once reported a healthy backup
        // as failed.
        $verification = (new DeepVerifyService)->deepVerify(
            $session,
            $s3->readClient(),
            $s3->bucket(),
            SessionLayoutResolver::for($session, $site),
            // On demand means one backup and a person waiting, so the sample is
            // wider than the nightly sweep's.
            sampleSize: 8,
        );

        $passed = $verification->status === BackupVerification::STATUS_PASSED;

        $this->backup->update([
            'verification_status' => $passed ? 'passed' : 'failed',
            'verification_message' => $passed
                ? 'Objects re-downloaded, re-hashed, archives opened and the dump parsed.'
                : ($verification->error ?: 'Deep verification failed.'),
            'verified_at' => now(),
        ]);
    }
}
