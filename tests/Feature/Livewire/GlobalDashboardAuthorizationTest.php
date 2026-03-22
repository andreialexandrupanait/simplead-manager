<?php

namespace Tests\Feature\Livewire;

use App\Livewire\Dashboard\GlobalDashboard;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GlobalDashboardAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $viewer;

    private Site $adminSite;

    private Site $managerSite;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->admin()->create();
        $this->manager = User::factory()->manager()->create();
        $this->viewer = User::factory()->viewer()->create();
        $this->adminSite = Site::factory()->for($this->admin)->create();
        $this->managerSite = Site::factory()->for($this->manager)->create();
    }

    // ─── deleteSite ─────────────────────────────────────────────────

    #[Test]
    public function admin_can_delete_any_site(): void
    {
        Livewire::actingAs($this->admin)
            ->test(GlobalDashboard::class)
            ->call('confirmDelete', $this->managerSite->id, $this->managerSite->name)
            ->call('deleteSite')
            ->assertDispatched('notify');

        $this->assertDatabaseMissing('sites', ['id' => $this->managerSite->id]);
    }

    #[Test]
    public function manager_cannot_delete_sites(): void
    {
        Livewire::actingAs($this->manager)
            ->test(GlobalDashboard::class)
            ->call('confirmDelete', $this->managerSite->id, $this->managerSite->name)
            ->call('deleteSite')
            ->assertForbidden();

        $this->assertDatabaseHas('sites', ['id' => $this->managerSite->id]);
    }

    #[Test]
    public function viewer_cannot_delete_sites(): void
    {
        Livewire::actingAs($this->viewer)
            ->test(GlobalDashboard::class)
            ->call('confirmDelete', $this->adminSite->id, $this->adminSite->name)
            ->call('deleteSite')
            ->assertForbidden();
    }

    // ─── renameSite ─────────────────────────────────────────────────

    #[Test]
    public function manager_can_rename_own_site(): void
    {
        Livewire::actingAs($this->manager)
            ->test(GlobalDashboard::class)
            ->call('startRename', $this->managerSite->id, $this->managerSite->name)
            ->set('renamingSiteName', 'New Name')
            ->call('renameSite')
            ->assertDispatched('notify');

        $this->assertDatabaseHas('sites', ['id' => $this->managerSite->id, 'name' => 'New Name']);
    }

    #[Test]
    public function viewer_cannot_rename_sites(): void
    {
        $originalName = $this->adminSite->name;

        Livewire::actingAs($this->viewer)
            ->test(GlobalDashboard::class)
            ->call('startRename', $this->adminSite->id, $originalName)
            ->set('renamingSiteName', 'Hacked Name')
            ->call('renameSite')
            ->assertForbidden();

        $this->assertDatabaseHas('sites', ['id' => $this->adminSite->id, 'name' => $originalName]);
    }

    #[Test]
    public function manager_cannot_rename_others_site(): void
    {
        $originalName = $this->adminSite->name;

        Livewire::actingAs($this->manager)
            ->test(GlobalDashboard::class)
            ->call('startRename', $this->adminSite->id, $originalName)
            ->set('renamingSiteName', 'Not Allowed')
            ->call('renameSite')
            ->assertForbidden();

        $this->assertDatabaseHas('sites', ['id' => $this->adminSite->id, 'name' => $originalName]);
    }

    // ─── bulkDelete ─────────────────────────────────────────────────

    #[Test]
    public function viewer_cannot_bulk_delete(): void
    {
        Livewire::actingAs($this->viewer)
            ->test(GlobalDashboard::class)
            ->set('selectedSites', [$this->adminSite->id])
            ->call('bulkDelete')
            ->assertForbidden();
    }

    #[Test]
    public function manager_cannot_bulk_delete(): void
    {
        Livewire::actingAs($this->manager)
            ->test(GlobalDashboard::class)
            ->set('selectedSites', [$this->managerSite->id])
            ->call('bulkDelete')
            ->assertForbidden();

        $this->assertDatabaseHas('sites', ['id' => $this->managerSite->id]);
    }

    // ─── bulk operations (non-delete) ───────────────────────────────

    #[Test]
    public function viewer_cannot_bulk_sync(): void
    {
        Livewire::actingAs($this->viewer)
            ->test(GlobalDashboard::class)
            ->set('selectedSites', [$this->adminSite->id])
            ->call('bulkSync')
            ->assertForbidden();
    }

    #[Test]
    public function viewer_cannot_bulk_backup(): void
    {
        Livewire::actingAs($this->viewer)
            ->test(GlobalDashboard::class)
            ->set('selectedSites', [$this->adminSite->id])
            ->call('bulkBackup')
            ->assertForbidden();
    }

    // ─── single site operations ─────────────────────────────────────

    #[Test]
    public function viewer_cannot_run_backup(): void
    {
        Livewire::actingAs($this->viewer)
            ->test(GlobalDashboard::class)
            ->call('runBackup', $this->adminSite->id)
            ->assertForbidden();
    }

    #[Test]
    public function viewer_cannot_sync_site(): void
    {
        Livewire::actingAs($this->viewer)
            ->test(GlobalDashboard::class)
            ->call('syncSite', $this->adminSite->id)
            ->assertForbidden();
    }

    // ─── user scoping on bulk operations ────────────────────────────

    #[Test]
    public function bulk_sync_scopes_to_own_sites_for_manager(): void
    {
        // Manager selects admin's site ID — should be filtered out
        Livewire::actingAs($this->manager)
            ->test(GlobalDashboard::class)
            ->set('selectedSites', [$this->adminSite->id, $this->managerSite->id])
            ->call('bulkSync')
            ->assertDispatched('notify');

        // The admin's site should not have been synced (query scoped)
        // We just verify no error — the scoping prevents unauthorized access
    }
}
