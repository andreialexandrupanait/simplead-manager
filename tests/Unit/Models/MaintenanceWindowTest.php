<?php

namespace Tests\Unit\Models;

use App\Models\MaintenanceWindow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\Traits\CreatesSite;

class MaintenanceWindowTest extends TestCase
{
    use RefreshDatabase, CreatesSite;

    public function test_is_pausing_returns_true_for_paused_type(): void
    {
        $site = $this->createSite();

        $window = MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'pause_uptime' => true,
            'pause_ssl' => true,
            'scheduled_start_at' => now()->subHour(),
            'scheduled_end_at' => now()->addHour(),
            'actual_start_at' => now()->subHour(),
        ]);

        $this->assertTrue($window->isPausing('uptime'));
        $this->assertTrue($window->isPausing('ssl'));
    }

    public function test_is_pausing_returns_false_for_non_paused_type(): void
    {
        $site = $this->createSite();

        $window = MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'pause_uptime' => true,
            'pause_ssl' => false,
            'pause_performance' => false,
            'pause_backups' => false,
            'pause_links' => false,
            'scheduled_start_at' => now()->subHour(),
            'scheduled_end_at' => now()->addHour(),
            'actual_start_at' => now()->subHour(),
        ]);

        $this->assertFalse($window->isPausing('ssl'));
        $this->assertFalse($window->isPausing('performance'));
        $this->assertFalse($window->isPausing('backups'));
        $this->assertFalse($window->isPausing('links'));

        // Also test that non-active windows always return false
        $scheduledWindow = MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
            'status' => 'scheduled',
            'pause_uptime' => true,
        ]);

        $this->assertFalse($scheduledWindow->isPausing('uptime'));
    }

    public function test_factory_creates_valid_record(): void
    {
        $site = $this->createSite();

        $window = MaintenanceWindow::factory()->create([
            'site_id' => $site->id,
        ]);

        $this->assertDatabaseHas('maintenance_windows', ['id' => $window->id]);
        $this->assertNotNull($window->title);
        $this->assertNotNull($window->scheduled_start_at);
        $this->assertNotNull($window->scheduled_end_at);
        $this->assertEquals('scheduled', $window->status);
    }
}
