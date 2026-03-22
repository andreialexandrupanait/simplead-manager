<?php

namespace Tests\Unit\Services;

use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DashboardCacheTest extends TestCase
{
    use RefreshDatabase;

    private DashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(DashboardService::class);
        User::factory()->admin()->create();
        Cache::flush();
    }

    #[Test]
    public function get_stats_caches_results(): void
    {
        $first = $this->service->getStats();
        $second = $this->service->getStats();

        $this->assertEquals($first, $second);
        $this->assertTrue(Cache::has('dashboard:stats'));
    }

    #[Test]
    public function get_alerts_caches_results(): void
    {
        $this->service->getAlerts();

        $this->assertTrue(Cache::has('dashboard:alerts'));
    }

    #[Test]
    public function invalidate_cache_clears_all_dashboard_keys(): void
    {
        $this->service->getStats();
        $this->service->getAlerts();
        $this->service->getSummaryStats();
        $this->service->getHealthDistribution();
        $this->service->getBackupStatus();

        $this->assertTrue(Cache::has('dashboard:stats'));
        $this->assertTrue(Cache::has('dashboard:alerts'));
        $this->assertTrue(Cache::has('dashboard:summary_stats'));
        $this->assertTrue(Cache::has('dashboard:health_distribution'));
        $this->assertTrue(Cache::has('dashboard:backup_status'));

        DashboardService::invalidateCache();

        $this->assertFalse(Cache::has('dashboard:stats'));
        $this->assertFalse(Cache::has('dashboard:alerts'));
        $this->assertFalse(Cache::has('dashboard:summary_stats'));
        $this->assertFalse(Cache::has('dashboard:health_distribution'));
        $this->assertFalse(Cache::has('dashboard:backup_status'));
    }

    #[Test]
    public function summary_stats_reuses_cached_stats(): void
    {
        // Call getStats first to populate cache
        $stats = $this->service->getStats();

        // summary_stats should reuse cached stats values
        $summary = $this->service->getSummaryStats();

        $this->assertEquals($stats['pending_updates'], $summary['pending_updates']);
        $this->assertEquals($stats['failed_backups'], $summary['failed_backups']);
    }
}
