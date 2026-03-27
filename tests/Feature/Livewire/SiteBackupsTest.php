<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire;

use App\Jobs\CreateBackup;
use App\Livewire\Sites\Detail\SiteBackups;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SiteBackupsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Site $site;

    private StorageDestination $destination;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->site = Site::factory()->for($this->admin)->create();
        $this->destination = StorageDestination::factory()->default()->create();
    }

    // ─── Rendering ────────────────────────────────────────────────────

    #[Test]
    public function user_can_view_backup_history(): void
    {
        Backup::factory()
            ->count(3)
            ->for($this->site)
            ->for($this->destination)
            ->completed()
            ->create();

        Livewire::actingAs($this->admin)
            ->test(SiteBackups::class, ['site' => $this->site])
            ->assertOk();
    }

    // ─── backupDatabase() ─────────────────────────────────────────────

    #[Test]
    public function user_can_trigger_database_backup(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SiteBackups::class, ['site' => $this->site])
            ->call('backupDatabase');

        Queue::assertPushed(CreateBackup::class, function (CreateBackup $job) {
            return $job->site->id === $this->site->id
                && $job->type === 'database'
                && $job->trigger === 'manual';
        });
    }

    // ─── backupFull() ─────────────────────────────────────────────────

    #[Test]
    public function user_can_trigger_full_backup(): void
    {
        Queue::fake();

        Livewire::actingAs($this->admin)
            ->test(SiteBackups::class, ['site' => $this->site])
            ->call('backupFull');

        Queue::assertPushed(CreateBackup::class, function (CreateBackup $job) {
            return $job->site->id === $this->site->id
                && $job->type === 'full'
                && $job->trigger === 'manual';
        });
    }

    // ─── toggleLock() ─────────────────────────────────────────────────

    #[Test]
    public function user_can_toggle_backup_lock(): void
    {
        $backup = Backup::factory()
            ->for($this->site)
            ->for($this->destination)
            ->completed()
            ->create(['is_locked' => false]);

        Livewire::actingAs($this->admin)
            ->test(SiteBackups::class, ['site' => $this->site])
            ->call('toggleLock', $backup->id);

        $this->assertDatabaseHas('backups', [
            'id' => $backup->id,
            'is_locked' => true,
        ]);

        // Toggle back to unlocked
        Livewire::actingAs($this->admin)
            ->test(SiteBackups::class, ['site' => $this->site])
            ->call('toggleLock', $backup->id);

        $this->assertDatabaseHas('backups', [
            'id' => $backup->id,
            'is_locked' => false,
        ]);
    }

    // ─── deleteBackup() ───────────────────────────────────────────────

    #[Test]
    public function user_can_delete_unlocked_backup(): void
    {
        $backup = Backup::factory()
            ->for($this->site)
            ->for($this->destination)
            ->completed()
            ->create([
                'is_locked' => false,
                'file_path' => null, // Avoid StorageFactory calls
            ]);

        Livewire::actingAs($this->admin)
            ->test(SiteBackups::class, ['site' => $this->site])
            ->call('deleteBackup', $backup->id);

        $this->assertDatabaseMissing('backups', ['id' => $backup->id]);
    }

    #[Test]
    public function user_cannot_delete_locked_backup(): void
    {
        $backup = Backup::factory()
            ->for($this->site)
            ->for($this->destination)
            ->completed()
            ->locked()
            ->create();

        Livewire::actingAs($this->admin)
            ->test(SiteBackups::class, ['site' => $this->site])
            ->call('deleteBackup', $backup->id);

        // Backup must still exist — locked backups cannot be deleted
        $this->assertDatabaseHas('backups', ['id' => $backup->id]);
    }
}
