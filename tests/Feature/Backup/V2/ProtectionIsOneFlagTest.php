<?php

declare(strict_types=1);

namespace Tests\Feature\Backup\V2;

use App\Backup\V2\Enums\BackupSessionState;
use App\Backup\V2\Models\BackupSession;
use App\Backup\V2\Orchestration\SessionActions;
use App\Enums\BackupStatus;
use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SiteBackups;
use App\Models\Backup;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use RuntimeException;
use Tests\Feature\Backup\V2\Ui\MakesV2Sessions;
use Tests\TestCase;

/**
 * Protecting a backup protects it from everything.
 *
 * There were two flags on two tables and neither knew about the other:
 * `backups.is_locked`, which the padlock on the site's Backups page sets and
 * which retention consults, and `backup_sessions.protected`, which the engine's
 * own delete consults. So it broke in both directions, and one of them lost
 * data:
 *
 * - Pinned through the engine: SessionActions::delete() refused, and the nightly
 *   retention pass — which reads is_locked and nothing else — reclaimed it
 *   anyway. Somebody protected a restore point and the system deleted it.
 * - Locked from the page: retention honoured it, and the engine's delete removed
 *   it without a word, because it never looked at that column.
 *
 * Neither failure announces itself. The backup is simply gone, with a padlock
 * still drawn next to where it used to be.
 */
class ProtectionIsOneFlagTest extends TestCase
{
    use MakesV2Sessions, RefreshDatabase;

    private User $admin;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->site = Site::factory()->create(['user_id' => $this->admin->id]);
    }

    /** @return array{0: Backup, 1: BackupSession} */
    private function restorePoint(): array
    {
        $backup = Backup::factory()->create([
            'site_id' => $this->site->id,
            'status' => BackupStatus::Completed,
            'is_locked' => false,
        ]);

        $session = $this->makeBackupSession($this->site, [
            'state' => BackupSessionState::Completed,
            'backup_id' => $backup->id,
        ]);

        return [$backup, $session];
    }

    public function test_protecting_through_the_engine_also_stops_retention(): void
    {
        [$backup, $session] = $this->restorePoint();

        app(SessionActions::class)->setProtected($session, true);

        // is_locked is the column retention reads. Without it, the nightly pass
        // reclaimed a restore point somebody had just pinned.
        $this->assertTrue((bool) $backup->fresh()->is_locked);
    }

    public function test_unprotecting_releases_both(): void
    {
        [$backup, $session] = $this->restorePoint();
        $actions = app(SessionActions::class);

        $actions->setProtected($session, true);
        $actions->setProtected($session->fresh(), false);

        $this->assertFalse((bool) $backup->fresh()->is_locked);
        $this->assertFalse((bool) $session->fresh()->protected);
    }

    public function test_the_padlock_on_the_page_also_stops_the_engine_deleting_it(): void
    {
        [$backup, $session] = $this->restorePoint();

        Livewire::actingAs($this->admin)
            ->test(SiteBackups::class, ['site' => $this->site])
            ->call('toggleLock', $backup->id);

        $this->assertTrue((bool) $session->fresh()->protected);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete a protected backup');

        app(SessionActions::class)->delete($session->fresh());
    }

    /**
     * Belt and braces: even if the two columns somehow drift again, the engine's
     * delete refuses on either one rather than on its own alone.
     */
    public function test_a_row_locked_without_the_session_still_refuses_deletion(): void
    {
        [$backup, $session] = $this->restorePoint();

        $backup->update(['is_locked' => true, 'lock_reason' => 'manual']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Cannot delete a protected backup');

        app(SessionActions::class)->delete($session->fresh());
    }
}
