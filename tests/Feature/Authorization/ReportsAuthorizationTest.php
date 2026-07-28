<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\UserRole;
use App\Livewire\Reports\ReportsOverview;
use App\Models\Report;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Regression coverage for the report authorization findings (E-37 report IDOR trio).
 */
class ReportsAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_delete_a_report(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::Viewer]);
        $site = Site::factory()->create(['user_id' => $viewer->id]);
        $report = Report::factory()->create(['site_id' => $site->id]);

        Livewire::actingAs($viewer)
            ->test(ReportsOverview::class)
            ->call('deleteReport', $report->id)
            ->assertForbidden();

        $this->assertDatabaseHas('reports', ['id' => $report->id]);
    }

    public function test_manager_cannot_delete_another_owners_report(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Manager]);
        $intruder = User::factory()->create(['role' => UserRole::Manager]);
        $site = Site::factory()->create(['user_id' => $owner->id]);
        $report = Report::factory()->create(['site_id' => $site->id]);

        Livewire::actingAs($intruder)
            ->test(ReportsOverview::class)
            ->call('deleteReport', $report->id)
            ->assertForbidden();

        $this->assertDatabaseHas('reports', ['id' => $report->id]);
    }
}
