<?php

namespace Tests\Unit\Services;

use App\Models\Backup;
use App\Models\Site;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardServiceTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $service;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardService::class);
        $this->user = User::factory()->admin()->create();
        Cache::flush();
    }

    #[Test]
    public function get_stats_returns_expected_structure(): void
    {
        $stats = $this->service->getStats();

        $this->assertArrayHasKey('sites_down', $stats);
        $this->assertArrayHasKey('avg_uptime', $stats);
        $this->assertArrayHasKey('avg_response_time', $stats);
        $this->assertArrayHasKey('pending_updates', $stats);
        $this->assertArrayHasKey('failed_backups', $stats);
        $this->assertArrayHasKey('domains_expiring', $stats);
    }

    #[Test]
    public function get_stats_counts_sites_down(): void
    {
        Site::factory()->for($this->user)->create(['is_up' => true]);
        Site::factory()->for($this->user)->create(['is_up' => false]);
        Site::factory()->for($this->user)->create(['is_up' => false]);

        $stats = $this->service->getStats();

        $this->assertSame(2, $stats['sites_down']);
    }

    #[Test]
    public function get_stats_counts_failed_backups_in_last_day(): void
    {
        $site = Site::factory()->for($this->user)->create();

        Backup::factory()->for($site)->failed()->create(['created_at' => now()]);
        Backup::factory()->for($site)->failed()->create(['created_at' => now()->subHours(12)]);
        Backup::factory()->for($site)->failed()->create(['created_at' => now()->subDays(2)]); // Old, shouldn't count

        $stats = $this->service->getStats();

        $this->assertSame(2, $stats['failed_backups']);
    }

    #[Test]
    public function get_stats_is_cached(): void
    {
        $stats1 = $this->service->getStats();

        // Create a site after caching
        Site::factory()->for($this->user)->create(['is_up' => false]);

        $stats2 = $this->service->getStats();

        $this->assertSame($stats1['sites_down'], $stats2['sites_down']);
    }

    #[Test]
    public function get_health_distribution_returns_correct_structure(): void
    {
        $distribution = $this->service->getHealthDistribution();

        $this->assertArrayHasKey('labels', $distribution);
        $this->assertArrayHasKey('values', $distribution);
        $this->assertArrayHasKey('colors', $distribution);
        $this->assertCount(4, $distribution['labels']);
        $this->assertCount(4, $distribution['values']);
    }

    #[Test]
    public function get_health_distribution_categorizes_correctly(): void
    {
        Site::factory()->for($this->user)->create(['health_score' => 80, 'is_up' => true]); // healthy
        Site::factory()->for($this->user)->create(['health_score' => 60, 'is_up' => true]); // warning
        Site::factory()->for($this->user)->create(['health_score' => 30, 'is_up' => true]); // critical
        Site::factory()->for($this->user)->create(['health_score' => 90, 'is_up' => false]); // down

        $distribution = $this->service->getHealthDistribution();

        $this->assertSame(1, $distribution['values'][0]); // healthy
        $this->assertSame(1, $distribution['values'][1]); // warning
        $this->assertSame(1, $distribution['values'][2]); // critical
        $this->assertSame(1, $distribution['values'][3]); // down
    }

    #[Test]
    public function get_alerts_returns_alerts_for_down_sites(): void
    {
        Site::factory()->for($this->user)->create(['is_up' => false, 'name' => 'Down Site']);

        $alerts = $this->service->getAlerts();

        $this->assertNotEmpty($alerts);
        $this->assertSame('critical', $alerts[0]['severity']);
        $this->assertStringContainsString('Down Site', $alerts[0]['title']);
    }

    #[Test]
    public function get_alerts_returns_empty_when_everything_ok(): void
    {
        Site::factory()->healthy()->for($this->user)->create();

        $alerts = $this->service->getAlerts();

        $this->assertEmpty($alerts);
    }

    #[Test]
    public function get_backup_status_returns_expected_structure(): void
    {
        $status = $this->service->getBackupStatus();

        $this->assertArrayHasKey('backups_today', $status);
        $this->assertArrayHasKey('failed_backups', $status);
        $this->assertArrayHasKey('total_storage_gb', $status);
        $this->assertArrayHasKey('sites_without_backup', $status);
    }

    #[Test]
    public function get_summary_stats_returns_expected_structure(): void
    {
        $stats = $this->service->getSummaryStats();

        $this->assertArrayHasKey('backups_today', $stats);
        $this->assertArrayHasKey('failed_backups', $stats);
        $this->assertArrayHasKey('total_storage', $stats);
        $this->assertArrayHasKey('pending_updates', $stats);
        $this->assertArrayHasKey('domains_expiring', $stats);
    }
}
