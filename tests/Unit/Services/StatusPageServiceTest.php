<?php

namespace Tests\Unit\Services;

use App\Models\MaintenanceWindow;
use App\Models\StatusPage;
use App\Models\StatusPageIncident;
use App\Models\StatusPageIncidentUpdate;
use App\Services\StatusPageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class StatusPageServiceTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    // ------------------------------------------------------------------ //
    //  createAutoIncident
    // ------------------------------------------------------------------ //

    public function test_create_auto_incident_creates_incident_with_investigating_status(): void
    {
        $statusPage = StatusPage::factory()->create();
        $site = $this->createSite();

        $incident = StatusPageService::createAutoIncident($statusPage, $site, 'Site is down');

        $this->assertInstanceOf(StatusPageIncident::class, $incident);
        $this->assertEquals('investigating', $incident->status);
        $this->assertEquals('major', $incident->severity);
        $this->assertTrue($incident->auto_created);
        $this->assertDatabaseHas('status_page_incidents', [
            'id' => $incident->id,
            'status_page_id' => $statusPage->id,
            'site_id' => $site->id,
            'status' => 'investigating',
        ]);
    }

    public function test_create_auto_incident_does_not_create_duplicate(): void
    {
        $statusPage = StatusPage::factory()->create();
        $site = $this->createSite();

        $first = StatusPageService::createAutoIncident($statusPage, $site, 'Site is down');
        $second = StatusPageService::createAutoIncident($statusPage, $site, 'Still down');

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, StatusPageIncident::where('status_page_id', $statusPage->id)
            ->where('site_id', $site->id)
            ->where('auto_created', true)
            ->count());
    }

    public function test_create_auto_incident_creates_incident_update(): void
    {
        $statusPage = StatusPage::factory()->create();
        $site = $this->createSite();

        $incident = StatusPageService::createAutoIncident($statusPage, $site, 'Site is down');

        $this->assertDatabaseHas('status_page_incident_updates', [
            'status_page_incident_id' => $incident->id,
            'status' => 'investigating',
        ]);
    }

    // ------------------------------------------------------------------ //
    //  resolveAutoIncident
    // ------------------------------------------------------------------ //

    public function test_resolve_auto_incident_resolves_active_auto_incidents(): void
    {
        $statusPage = StatusPage::factory()->create();
        $site = $this->createSite();

        $incident = StatusPageService::createAutoIncident($statusPage, $site, 'Site is down');

        StatusPageService::resolveAutoIncident($statusPage, $site);

        $incident->refresh();
        $this->assertEquals('resolved', $incident->status);
        $this->assertNotNull($incident->resolved_at);
    }

    public function test_resolve_auto_incident_creates_resolved_update(): void
    {
        $statusPage = StatusPage::factory()->create();
        $site = $this->createSite();

        $incident = StatusPageService::createAutoIncident($statusPage, $site, 'Site is down');

        StatusPageService::resolveAutoIncident($statusPage, $site);

        $resolvedUpdate = StatusPageIncidentUpdate::where('status_page_incident_id', $incident->id)
            ->where('status', 'resolved')
            ->first();

        $this->assertNotNull($resolvedUpdate);
        $this->assertStringContainsString('recovered', $resolvedUpdate->message);
    }

    // ------------------------------------------------------------------ //
    //  createMaintenanceIncident
    // ------------------------------------------------------------------ //

    public function test_create_maintenance_incident_creates_scheduled_incident(): void
    {
        $statusPage = StatusPage::factory()->create();
        $site = $this->createSite();
        $window = MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
            'title' => 'Server upgrade',
        ]);

        $incident = StatusPageService::createMaintenanceIncident($statusPage, $window);

        $this->assertInstanceOf(StatusPageIncident::class, $incident);
        $this->assertTrue($incident->is_scheduled);
        $this->assertTrue($incident->auto_created);
        $this->assertStringContainsString('Scheduled Maintenance', $incident->title);
    }

    // ------------------------------------------------------------------ //
    //  resolveMaintenanceIncident
    // ------------------------------------------------------------------ //

    public function test_resolve_maintenance_incident_resolves_scheduled_incidents(): void
    {
        $statusPage = StatusPage::factory()->create();
        $site = $this->createSite();
        $window = MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
        ]);

        $incident = StatusPageService::createMaintenanceIncident($statusPage, $window);

        StatusPageService::resolveMaintenanceIncident($statusPage, $window);

        $incident->refresh();
        $this->assertEquals('resolved', $incident->status);
        $this->assertNotNull($incident->resolved_at);
    }

    // ------------------------------------------------------------------ //
    //  getPublicData
    // ------------------------------------------------------------------ //

    public function test_get_public_data_returns_correct_structure(): void
    {
        $statusPage = StatusPage::factory()->create([
            'title' => 'My Status Page',
            'description' => 'Service status',
        ]);

        $data = StatusPageService::getPublicData($statusPage);

        $this->assertIsArray($data);
        $this->assertArrayHasKey('title', $data);
        $this->assertArrayHasKey('description', $data);
        $this->assertArrayHasKey('overall_status', $data);
        $this->assertArrayHasKey('sites', $data);
        $this->assertArrayHasKey('active_incidents', $data);
        $this->assertArrayHasKey('recent_incidents', $data);
        $this->assertArrayHasKey('scheduled_maintenance', $data);
        $this->assertArrayHasKey('show_uptime_percentage', $data);
        $this->assertArrayHasKey('show_response_time', $data);
        $this->assertArrayHasKey('show_incident_history', $data);
        $this->assertEquals('My Status Page', $data['title']);
    }

    public function test_get_public_data_caches_results(): void
    {
        $statusPage = StatusPage::factory()->create();

        Cache::shouldReceive('remember')
            ->once()
            ->with("status-page:{$statusPage->id}", 60, \Closure::class)
            ->andReturn([
                'title' => $statusPage->title,
                'description' => $statusPage->description,
                'logo_url' => null,
                'primary_color' => $statusPage->primary_color,
                'overall_status' => 'operational',
                'show_uptime_percentage' => true,
                'show_response_time' => false,
                'show_incident_history' => true,
                'sites' => collect([]),
                'active_incidents' => collect([]),
                'recent_incidents' => collect([]),
                'scheduled_maintenance' => collect([]),
            ]);

        $data = StatusPageService::getPublicData($statusPage);

        $this->assertEquals($statusPage->title, $data['title']);
    }

    // ------------------------------------------------------------------ //
    //  verifyPassword
    // ------------------------------------------------------------------ //

    public function test_verify_password_returns_true_for_correct_password(): void
    {
        $statusPage = StatusPage::factory()->create([
            'password_hash' => Hash::make('my-secret'),
        ]);

        $this->assertTrue(StatusPageService::verifyPassword($statusPage, 'my-secret'));
    }

    public function test_verify_password_returns_false_for_wrong_password(): void
    {
        $statusPage = StatusPage::factory()->create([
            'password_hash' => Hash::make('my-secret'),
        ]);

        $this->assertFalse(StatusPageService::verifyPassword($statusPage, 'wrong-password'));
    }
}
