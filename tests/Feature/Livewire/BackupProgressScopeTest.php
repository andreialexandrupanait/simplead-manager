<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Enums\BackupStatus;
use App\Enums\UserRole;
use App\Livewire\Sites\Detail\SiteBackups;
use App\Models\Backup;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P1-29: cancelBackup() and the progress computeds resolved the backup by the
 * client-hydrated `trackingBackupId` with an UNSCOPED `Backup::find()`, so a
 * tampered id pointing at another site's backup could be cancelled / read even
 * though the component authorized only its own site. The lookups are now
 * scoped to `$this->site->backups()`.
 */
class BackupProgressScopeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $siteA;

    private Site $siteB;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        Queue::fake();
        Http::fake();

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->siteA = Site::factory()->create(['user_id' => $this->admin->id]);
        $this->siteB = Site::factory()->create(['user_id' => $this->admin->id]);
    }

    public function test_cancel_cannot_touch_a_backup_from_another_site(): void
    {
        $victim = Backup::factory()->create([
            'site_id' => $this->siteB->id,
            'status' => BackupStatus::InProgress,
        ]);

        Livewire::actingAs($this->admin)
            ->test(SiteBackups::class, ['site' => $this->siteA])
            ->set('trackingBackupId', $victim->id)
            ->call('cancelBackup');

        // The cross-site backup must be untouched.
        $this->assertSame(BackupStatus::InProgress, $victim->fresh()->status);
    }

    public function test_cancel_still_works_for_own_site_backup(): void
    {
        $own = Backup::factory()->create([
            'site_id' => $this->siteA->id,
            'status' => BackupStatus::InProgress,
            'started_at' => now(),
        ]);

        Livewire::actingAs($this->admin)
            ->test(SiteBackups::class, ['site' => $this->siteA])
            ->set('trackingBackupId', $own->id)
            ->call('cancelBackup');

        $this->assertSame(BackupStatus::Cancelled, $own->fresh()->status);
    }

    public function test_active_backup_computed_is_scoped_to_site(): void
    {
        $victim = Backup::factory()->create([
            'site_id' => $this->siteB->id,
            'status' => BackupStatus::InProgress,
        ]);

        $component = Livewire::actingAs($this->admin)
            ->test(SiteBackups::class, ['site' => $this->siteA])
            ->set('trackingBackupId', $victim->id);

        // The computed must not surface a cross-site backup.
        $this->assertNull($component->instance()->activeBackup());
    }
}
