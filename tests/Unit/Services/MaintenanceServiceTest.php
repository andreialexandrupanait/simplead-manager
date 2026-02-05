<?php

namespace Tests\Unit\Services;

use App\Models\ActivityLog;
use App\Models\MaintenanceWindow;
use App\Models\User;
use App\Services\MaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class MaintenanceServiceTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    public function test_is_site_in_maintenance_returns_false_when_no_active_window(): void
    {
        $site = $this->createSite();

        $result = MaintenanceService::isSiteInMaintenance($site, 'uptime');

        $this->assertFalse($result);
    }

    public function test_is_site_in_maintenance_returns_true_when_active_window_pauses_type(): void
    {
        $site = $this->createSite();

        MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'pause_uptime' => true,
            'scheduled_start_at' => now()->subHour(),
            'scheduled_end_at' => now()->addHour(),
            'actual_start_at' => now()->subHour(),
        ]);

        // Reload the relationship
        $site->load('activeMaintenanceWindow');

        $result = MaintenanceService::isSiteInMaintenance($site, 'uptime');

        $this->assertTrue($result);
    }

    public function test_is_site_in_maintenance_returns_false_when_active_window_does_not_pause_type(): void
    {
        $site = $this->createSite();

        MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'pause_uptime' => true,
            'pause_ssl' => false,
            'scheduled_start_at' => now()->subHour(),
            'scheduled_end_at' => now()->addHour(),
            'actual_start_at' => now()->subHour(),
        ]);

        $site->load('activeMaintenanceWindow');

        $result = MaintenanceService::isSiteInMaintenance($site, 'ssl');

        $this->assertFalse($result);
    }

    public function test_process_scheduled_windows_starts_overdue_windows(): void
    {
        $site = $this->createSite();

        $window = MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
            'status' => 'scheduled',
            'scheduled_start_at' => now()->subMinutes(10),
            'scheduled_end_at' => now()->addHour(),
            'notify_on_start' => false,
            'update_status_page' => false,
        ]);

        MaintenanceService::processScheduledWindows();

        $window->refresh();
        $this->assertEquals('active', $window->status);
        $this->assertNotNull($window->actual_start_at);
    }

    public function test_process_scheduled_windows_ignores_future_windows(): void
    {
        $site = $this->createSite();

        $window = MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
            'status' => 'scheduled',
            'scheduled_start_at' => now()->addHours(2),
            'scheduled_end_at' => now()->addHours(4),
        ]);

        MaintenanceService::processScheduledWindows();

        $window->refresh();
        $this->assertEquals('scheduled', $window->status);
        $this->assertNull($window->actual_start_at);
    }

    public function test_process_ending_windows_ends_overdue_active_windows(): void
    {
        $site = $this->createSite();

        $window = MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'scheduled_start_at' => now()->subHours(3),
            'scheduled_end_at' => now()->subMinutes(10),
            'actual_start_at' => now()->subHours(3),
            'notify_on_end' => false,
            'update_status_page' => false,
        ]);

        MaintenanceService::processEndingWindows();

        $window->refresh();
        $this->assertEquals('completed', $window->status);
        $this->assertNotNull($window->actual_end_at);
    }

    public function test_start_maintenance_sets_status_to_active(): void
    {
        $site = $this->createSite();

        $window = MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
            'status' => 'scheduled',
            'scheduled_start_at' => now()->subMinutes(5),
            'scheduled_end_at' => now()->addHour(),
            'notify_on_start' => false,
            'update_status_page' => false,
        ]);

        MaintenanceService::startMaintenance($window);

        $window->refresh();
        $this->assertEquals('active', $window->status);
        $this->assertNotNull($window->actual_start_at);
    }

    public function test_start_maintenance_creates_activity_log(): void
    {
        $site = $this->createSite();

        $window = MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
            'status' => 'scheduled',
            'scheduled_start_at' => now()->subMinutes(5),
            'scheduled_end_at' => now()->addHour(),
            'notify_on_start' => false,
            'update_status_page' => false,
        ]);

        MaintenanceService::startMaintenance($window);

        $this->assertDatabaseHas('activity_logs', [
            'site_id' => $site->id,
            'type' => 'maintenance',
            'severity' => 'info',
        ]);

        $log = ActivityLog::where('site_id', $site->id)->where('type', 'maintenance')->first();
        $this->assertStringContains('Maintenance started', $log->title);
    }

    public function test_end_maintenance_sets_status_to_completed(): void
    {
        $site = $this->createSite();

        $window = MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'scheduled_start_at' => now()->subHours(2),
            'scheduled_end_at' => now()->subMinutes(5),
            'actual_start_at' => now()->subHours(2),
            'notify_on_end' => false,
            'update_status_page' => false,
        ]);

        MaintenanceService::endMaintenance($window);

        $window->refresh();
        $this->assertEquals('completed', $window->status);
        $this->assertNotNull($window->actual_end_at);
    }

    public function test_cancel_maintenance_sets_status_to_cancelled(): void
    {
        $site = $this->createSite();

        $window = MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
            'status' => 'scheduled',
            'scheduled_start_at' => now()->addHour(),
            'scheduled_end_at' => now()->addHours(3),
        ]);

        MaintenanceService::cancelMaintenance($window);

        $window->refresh();
        $this->assertEquals('cancelled', $window->status);
    }

    /**
     * Helper to assert a string contains a substring (PHPUnit 11 compatible).
     */
    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertStringContainsString($needle, $haystack);
    }
}
