<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Livewire\Backups\BackupsOverview;
use App\Livewire\MaintenancePlans;
use App\Models\Backup;
use App\Models\RollbackPoint;
use App\Models\Site;
use App\Models\User;
use App\Services\IncidentResponse\IncidentActionExecutor;
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

    public function test_incident_rollback_is_scoped_to_the_incident_site(): void
    {
        $siteA = Site::factory()->create();
        $siteB = Site::factory()->create();
        $pointOnB = RollbackPoint::factory()->create(['site_id' => $siteB->id, 'status' => 'available']);

        $executor = app(IncidentActionExecutor::class);
        $method = new \ReflectionMethod($executor, 'rollbackPlugin');
        $method->setAccessible(true);

        // A rollback point belonging to another site must never resolve.
        $result = $method->invoke($executor, $siteA, ['rollback_point_id' => $pointOnB->id]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not found for this site', $result['error']);
    }

    public function test_incident_rollback_rejects_a_used_point(): void
    {
        $site = Site::factory()->create();
        $usedPoint = RollbackPoint::factory()->create(['site_id' => $site->id, 'status' => 'used']);

        $executor = app(IncidentActionExecutor::class);
        $method = new \ReflectionMethod($executor, 'rollbackPlugin');
        $method->setAccessible(true);

        $result = $method->invoke($executor, $site, ['rollback_point_id' => $usedPoint->id]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not available', $result['error']);
    }
}
