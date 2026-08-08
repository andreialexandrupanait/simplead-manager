<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Verification;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Jobs\VerifyBackupSessionJob;
use App\Backup\V2\Models\BackupVerification;
use App\Backup\V2\Orchestration\SessionActions;
use App\Backup\V2\Storage\ObjectLayout;
use App\Backup\V2\Verification\BackupVerifier;
use App\Models\Site;
use Aws\S3\S3Client;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Feature\Backup\V2\Ui\MakesV2Sessions;
use Tests\TestCase;

/**
 * A backup may only claim to be verified if something looked at it.
 *
 * `verified_at` is not decoration: it is written through to the `backups` row,
 * where BackupHealthService scores a site on it. A stamp nobody earned reports a
 * site as protected by a backup that was never checked. The UI's Verify button
 * used to set it directly and inspect nothing.
 */
class HonestVerificationTest extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    private function pilotSite(): Site
    {
        $site = Site::factory()->create();
        config([
            'backup_v2.enabled' => true,
            'backup_v2.ui_enabled' => true,
            'backup_v2.site_ids' => [(string) $site->id],
        ]);

        return $site;
    }

    public function test_the_verify_button_no_longer_stamps_anything_itself(): void
    {
        Queue::fake();

        $site = $this->pilotSite();
        $session = $this->makeBackupSession($site, ['state' => BackupSessionState::Completed]);

        app(SessionActions::class)->requestVerification($session);

        $this->assertNull($session->fresh()->verified_at, 'Requesting verification must not assert its own result.');
        Queue::assertPushed(VerifyBackupSessionJob::class);
    }

    /**
     * Asking for a verification queues one; it does not stamp anything.
     *
     * This used to go through the V2 console's button. The console is gone and
     * the assertion is not about a screen — it is that requesting a check and
     * passing a check are different events, and only the second may write
     * verified_at.
     */
    public function test_requesting_a_verification_queues_it_without_stamping(): void
    {
        Queue::fake();

        $site = $this->pilotSite();
        $session = $this->makeBackupSession($site, ['state' => BackupSessionState::Completed]);

        app(\App\Backup\V2\Orchestration\SessionActions::class)->requestVerification($session);

        Queue::assertPushed(
            VerifyBackupSessionJob::class,
            fn (VerifyBackupSessionJob $job) => $job->sessionId === $session->id,
        );
        $this->assertNull($session->fresh()->verified_at);
    }

    /**
     * The heart of it: a backup that passed once and fails later must LOSE the
     * stamp. Leaving it was defensible for a first verification and wrong for
     * every one after — the backup would keep saying "verified" for as long as
     * it existed.
     *
     * Driven through the real verifier: unreachable storage takes the same path
     * a missing bucket does.
     */
    public function test_a_failing_verification_withdraws_an_existing_stamp(): void
    {
        $site = $this->pilotSite();
        $session = $this->makeBackupSession($site, [
            'state' => BackupSessionState::Completed,
            'verified_at' => now()->subDay(),
        ]);

        $this->assertNotNull($session->verified_at);

        $verification = (new BackupVerifier)->verifyOnComplete(
            $session,
            $this->unreachableS3(),
            'no-such-bucket',
            ObjectLayout::forBackup(0, $site->id, $session->id),
        );

        $this->assertFalse($verification->passed());
        $this->assertNull($session->fresh()->verified_at, 'a failed verification must withdraw the stamp');
    }

    /**
     * An operator-requested check must not masquerade as the automatic pass the
     * backup got at completion — the create-verification history keys off
     * `kind`, and proven-restore requires a passing `create` specifically.
     */
    public function test_a_manual_check_is_recorded_under_its_own_kind(): void
    {
        $site = $this->pilotSite();
        $session = $this->makeBackupSession($site, ['state' => BackupSessionState::Completed]);

        (new BackupVerifier)->verifyOnComplete(
            $session,
            $this->unreachableS3(),
            'no-such-bucket',
            ObjectLayout::forBackup(0, $site->id, $session->id),
            BackupVerification::KIND_MANUAL,
        );

        $this->assertDatabaseHas('backup_verifications', [
            'backup_session_id' => $session->id,
            'kind' => BackupVerification::KIND_MANUAL,
        ]);
        $this->assertDatabaseMissing('backup_verifications', [
            'backup_session_id' => $session->id,
            'kind' => BackupVerification::KIND_CREATE,
        ]);
    }

    /**
     * A verification that dies mid-flight leaves the backup unverified rather
     * than wearing a stamp it can no longer support.
     */
    public function test_a_crashed_verification_job_withdraws_the_stamp(): void
    {
        $site = $this->pilotSite();
        $session = $this->makeBackupSession($site, [
            'state' => BackupSessionState::Completed,
            'verified_at' => now()->subDay(),
        ]);

        (new VerifyBackupSessionJob($session->id))->failed(new \RuntimeException('S3 unreachable'));

        $this->assertNull($session->fresh()->verified_at);
    }

    /**
     * Points at a port nothing listens on, with the retries and timeouts wound
     * down so the failure is immediate rather than a 30-second stall.
     */
    private function unreachableS3(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => 'http://127.0.0.1:1',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'x', 'secret' => 'y'],
            'retries' => 0,
            'http' => ['connect_timeout' => 1, 'timeout' => 2],
        ]);
    }
}
