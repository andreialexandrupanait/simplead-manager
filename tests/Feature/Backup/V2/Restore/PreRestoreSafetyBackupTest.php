<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2\Restore;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Enums\RestoreSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Models\RestoreSession;
use App\Backup\V2\Restore\PreRestoreSafetyBackup;
use App\Enums\BackupEngine;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The safety copy taken before a restore touches a live site — the thing the guaranteed rollback
 * falls back to when the journaled swap is not enough.
 *
 * Both properties here were broken on the first live attempt, and the restore stopped one step
 * before the site would have been modified. It stopped because of a foreign key, not because
 * anything noticed: the closure returned a BackupSession id into a column that references `backups`.
 * And the session was created raw, with no `backups` row at all — so even had the types lined up,
 * the safety copy would have been invisible in the site's backup list, uncounted by storage, and
 * outside retention. A rollback point nobody can find is not much of a rollback point.
 */
class PreRestoreSafetyBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_something_the_restore_session_can_actually_store(): void
    {
        [$site, $destination] = $this->siteWithStorage();
        $restore = $this->restoreSessionFor($site);

        $safety = $this->safetyBackup($site, $destination);
        $returned = $safety($restore);

        $this->assertNotNull($returned, 'a completed safety backup must report itself');

        // The column references `backups`. Handing it a backup_sessions id is a foreign key
        // violation, which is precisely how this surfaced.
        $restore->pre_restore_backup_id = $returned;
        $restore->save();

        $this->assertDatabaseHas('restore_sessions', [
            'id' => $restore->id,
            'pre_restore_backup_id' => $returned,
        ]);
        $this->assertNotNull(Backup::find($returned), 'the id must name a row in `backups`');
    }

    public function test_the_safety_copy_shows_up_like_any_other_backup(): void
    {
        [$site, $destination] = $this->siteWithStorage();
        $restore = $this->restoreSessionFor($site);

        $returned = ($this->safetyBackup($site, $destination))($restore);

        $backup = Backup::findOrFail($returned);
        $this->assertSame($site->id, $backup->site_id);
        $this->assertSame(BackupEngine::V2, $backup->engine);
        $this->assertSame('pre_restore', $backup->trigger, 'so it is recognisable for what it is');
        $this->assertSame(
            $destination->id,
            $backup->storage_destination_id,
            'and retention can find it, rather than it sitting outside every policy',
        );

        $this->assertSame(
            $backup->id,
            BackupSession::where('site_id', $site->id)->latest('id')->first()?->backup_id,
            'the session and the row must know about each other',
        );
    }

    public function test_a_backup_that_did_not_complete_reports_nothing(): void
    {
        [$site, $destination] = $this->siteWithStorage();
        $restore = $this->restoreSessionFor($site);

        // A runner that leaves the session short of `completed`: the restore must refuse a MIRROR
        // rather than mutate a site with no way back.
        $safety = $this->safetyBackup($site, $destination, completes: false);

        $this->assertNull($safety($restore));
    }

    // ── harness ──────────────────────────────────────────────────────────

    /**
     * @return array{0: Site, 1: StorageDestination}
     */
    private function siteWithStorage(): array
    {
        $site = Site::factory()->create();
        $destination = StorageDestination::factory()->create([
            'type' => 'local',
            'config' => ['path' => sys_get_temp_dir().'/safety-'.uniqid()],
            'is_active' => true,
            'is_default' => true,
        ]);

        return [$site, $destination];
    }

    private function restoreSessionFor(Site $site): RestoreSession
    {
        $source = BackupSession::create([
            'site_id' => $site->id,
            'type' => 'full',
            'scope' => ['database' => true, 'files' => true],
            'resource_profile' => 'low_impact',
            'state' => BackupSessionState::Completed,
            'confirmed_objects' => [],
            'confirmed_parts' => [],
            'checkpoint' => [],
            'idempotency_key' => 'src-'.uniqid('', true),
            'format_version' => 'simplead-backup/2',
        ]);

        return RestoreSession::create([
            'site_id' => $site->id,
            'backup_session_id' => $source->id,
            'mode' => 'safe_merge',
            'scope' => ['database' => false, 'files' => true],
            'state' => RestoreSessionState::Requested,
            'checkpoint' => [],
            'idempotency_key' => 'safety-'.uniqid('', true),
        ]);
    }

    /**
     * The real class, with the BackupRunner short-circuited: what is under test is the bookkeeping
     * around the backup, not the backup itself, which BackupRunnerE2ETest covers against real
     * storage.
     */
    private function safetyBackup(Site $site, StorageDestination $destination, bool $completes = true): PreRestoreSafetyBackup
    {
        return new class($site, $destination, $completes) extends PreRestoreSafetyBackup
        {
            public function __construct(
                Site $site,
                StorageDestination $destination,
                private readonly bool $completes,
            ) {
                parent::__construct(
                    site: $site,
                    client: new \Tests\Feature\Backup\V2\Support\FakePluginClient(0, []),
                    s3: new \Aws\S3\S3Client([
                        'version' => 'latest',
                        'region' => 'us-east-1',
                        'credentials' => ['key' => 'x', 'secret' => 'y'],
                    ]),
                    bucket: 'irrelevant',
                    logger: null,
                    destination: $destination,
                );
            }

            protected function runBackup(BackupSession $session): void
            {
                if ($this->completes) {
                    $session->update(['state' => BackupSessionState::Completed, 'completed_at' => now()]);
                }
            }
        };
    }
}
