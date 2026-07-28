<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Livewire\Backups\BackupsOverview;
use App\Livewire\MaintenancePlans;
use App\Models\Backup;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the 2026-07-10 audit's cross-tenant / destructive
 * authorization findings (E-02, E-03, E-04).
 */
class CrossTenantActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_delete_a_backup(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);
        $backup = Backup::factory()->create(['site_id' => $site->id, 'is_locked' => false]);

        Livewire::actingAs($viewer)
            ->test(BackupsOverview::class)
            ->call('deleteBackup', $backup->id)
            ->assertForbidden();

        $this->assertDatabaseHas('backups', ['id' => $backup->id]);
    }

    public function test_manager_cannot_delete_another_owners_backup(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Manager]);
        $intruder = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $owner->id]);
        $backup = Backup::factory()->create(['site_id' => $site->id, 'is_locked' => false]);

        Livewire::actingAs($intruder)
            ->test(BackupsOverview::class)
            ->call('deleteBackup', $backup->id)
            ->assertForbidden();

        $this->assertDatabaseHas('backups', ['id' => $backup->id]);
    }

    public function test_viewer_cannot_apply_a_maintenance_plan_to_all_sites(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        // The Viewer guard fires before the plan is even loaded, so any id works.
        Livewire::actingAs($viewer)
            ->test(MaintenancePlans::class)
            ->call('applyPlanToAll', 999)
            ->assertForbidden();
    }
}
