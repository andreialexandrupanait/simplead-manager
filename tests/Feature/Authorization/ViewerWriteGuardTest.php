<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Livewire\MaintenancePlans;
use App\Livewire\Sites\Detail\ReportRecommendationsManager;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Representative coverage for the Viewer-write authorization sweep (E-09):
 * a read-only Viewer must be blocked from mutating actions across the
 * site-detail components and the global plan manager. The guard itself is the
 * same authorizeSiteModification()/isViewer() pattern applied everywhere.
 */
class ViewerWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_blocked_on_site_detail_recommendation_mutation(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);

        Livewire::actingAs($viewer)
            ->test(ReportRecommendationsManager::class, ['site' => $site])
            ->call('regenerateSuggestions')
            ->assertForbidden();
    }

    public function test_manager_allowed_to_mount_recommendation_manager(): void
    {
        $manager = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $manager->id]);

        Livewire::actingAs($manager)
            ->test(ReportRecommendationsManager::class, ['site' => $site])
            ->assertOk();
    }

    public function test_viewer_blocked_on_maintenance_plan_delete(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);

        Livewire::actingAs($viewer)
            ->test(MaintenancePlans::class)
            ->call('delete')
            ->assertForbidden();
    }
}
