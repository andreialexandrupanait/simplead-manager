<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalPageSmokeTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    public function test_dashboard_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('dashboard'))
            ->assertOk();
    }

    public function test_backups_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('backups.index'))
            ->assertOk();
    }

    public function test_performance_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('performance.index'))
            ->assertOk();
    }

    public function test_uptime_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('uptime.index'))
            ->assertOk();
    }

    public function test_updates_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('updates.index'))
            ->assertOk();
    }

    public function test_activity_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('activity.index'))
            ->assertOk();
    }

    public function test_errors_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('errors.index'))
            ->assertOk();
    }

    public function test_clients_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('clients.index'))
            ->assertOk();
    }

    public function test_reports_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('reports.index'))
            ->assertOk();
    }

    public function test_status_pages_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('status-pages.index'))
            ->assertOk();
    }

    public function test_settings_general_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.general'))
            ->assertOk();
    }

    public function test_settings_notifications_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.notifications'))
            ->assertOk();
    }

    public function test_settings_profile_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.profile'))
            ->assertOk();
    }

    public function test_settings_integrations_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.integrations'))
            ->assertOk();
    }

    public function test_settings_report_templates_page_loads(): void
    {
        $this->actingAs($this->user)
            ->get(route('settings.report-templates'))
            ->assertOk();
    }
}
