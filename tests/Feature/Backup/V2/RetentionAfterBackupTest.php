<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Jobs\RunBackupSessionJob;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\Feature\Backup\V2\Ui\MakesV2Sessions;
use Tests\TestCase;

/**
 * A successful backup has to retire what the site no longer keeps.
 *
 * V1 did this at the end of every successful run — CreateBackup and CreateIncrementalBackup both
 * call RetentionService::apply() — and V2 called it from nowhere. The only V2 route into the service
 * was SessionActions::delete(), which is the console's delete button, so on the night the fleet moved
 * engines the per-site policy silently stopped being applied to anything. Nothing aged out. The
 * symptom is not an error: retention that does nothing looks exactly like retention that works,
 * until the bucket says otherwise, and by then the oldest restore point you wanted gone is a year old
 * and the archives you were waiting to expire never will.
 *
 * The service already understood this engine — deleteBackup() branches on `engine === V2` and removes
 * the whole object prefix rather than handing a prefix to DeleteObject. Only the call was missing,
 * which is exactly the kind of gap no test catches unless it asserts the call.
 */
class RetentionAfterBackupTest extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The runner is never reached: the session below is already terminal, so run() returns at
        // its first line. What is under test is what the job does AFTER the engine is finished.
        config()->set('backup_v2.enabled', true);
    }

    public function test_a_completed_session_applies_the_site_s_retention_policy(): void
    {
        Queue::fake();

        [$site, $destination] = $this->siteWithDestination();
        $session = $this->makeBackupSession($site, ['state' => BackupSessionState::Completed]);

        $retention = Mockery::mock(RetentionService::class);
        $retention->shouldReceive('apply')
            ->once()
            ->withArgs(fn (Site $s, StorageDestination $d) => $s->id === $site->id && $d->id === $destination->id);
        $this->app->instance(RetentionService::class, $retention);

        (new RunBackupSessionJob($session->id))->handle();
    }

    /**
     * Retention runs after the backup is already written, verified and recorded. A bucket that will
     * not answer the cleanup call has not damaged the restore point — so reporting the run as failed
     * would be a lie, and with tries = 1 it is a lie nobody gets a second chance to correct.
     */
    public function test_a_failing_retention_pass_does_not_fail_the_backup(): void
    {
        Queue::fake();

        [$site] = $this->siteWithDestination();
        $session = $this->makeBackupSession($site, ['state' => BackupSessionState::Completed]);

        $retention = Mockery::mock(RetentionService::class);
        $retention->shouldReceive('apply')->once()->andThrow(new \RuntimeException('bucket unreachable'));
        $this->app->instance(RetentionService::class, $retention);

        (new RunBackupSessionJob($session->id))->handle();

        $this->assertSame(
            BackupSessionState::Completed,
            $session->refresh()->state,
            'the backup stands on its own; the cleanup that follows it does not get to revoke it',
        );
    }

    /**
     * @return array{0: Site, 1: StorageDestination}
     */
    private function siteWithDestination(): array
    {
        // The engine writes objects, so S3ClientFactory rejects any other type outright and decrypts
        // the credentials on the way past. Nothing here is ever connected to — the session is already
        // terminal — but the client is still constructed before the runner is, so the shape has to be
        // real. (The default factory also picks a type at random, which alone would make this flaky.)
        $destination = StorageDestination::factory()->create([
            'type' => 's3',
            'is_default' => true,
            'is_active' => true,
            'config' => [
                'endpoint' => 'http://storage.invalid',
                'region' => 'us-east-1',
                'bucket' => 'irrelevant',
                'use_path_style' => true,
                'key' => encrypt('key'),
                'secret' => encrypt('secret'),
            ],
        ]);
        $site = Site::factory()->create();
        BackupConfig::factory()->create([
            'site_id' => $site->id,
            'storage_destination_id' => $destination->id,
        ]);

        config()->set('backup_v2.site_ids', [$site->id]);

        return [$site, $destination];
    }
}
