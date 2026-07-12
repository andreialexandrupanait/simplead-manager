<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\BackupConfig;
use App\Models\PerformanceMonitor;
use App\Models\Site;
use App\Models\UptimeMonitor;
use App\Services\ModuleConfigService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2-37: the per-site module config tables are one-row-per-site by design, but
 * performance_monitors / uptime_monitors / backup_configs lacked a
 * unique(site_id) constraint, so a race could insert duplicates. The DB now
 * enforces the invariant and the materialization keys on it via updateOrCreate.
 */
class ModuleConfigUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_performance_monitors_reject_a_second_row_per_site(): void
    {
        $site = Site::factory()->create();
        PerformanceMonitor::create(['site_id' => $site->id]);

        $this->expectException(QueryException::class);
        PerformanceMonitor::create(['site_id' => $site->id]);
    }

    public function test_uptime_monitors_reject_a_second_row_per_site(): void
    {
        $site = Site::factory()->create();
        UptimeMonitor::create(['site_id' => $site->id, 'url' => $site->url, 'status' => 'active', 'interval_minutes' => 5]);

        $this->expectException(QueryException::class);
        UptimeMonitor::create(['site_id' => $site->id, 'url' => $site->url, 'status' => 'active', 'interval_minutes' => 5]);
    }

    public function test_backup_configs_reject_a_second_row_per_site(): void
    {
        $site = Site::factory()->create();
        BackupConfig::create(['site_id' => $site->id, 'is_enabled' => true]);

        $this->expectException(QueryException::class);
        BackupConfig::create(['site_id' => $site->id, 'is_enabled' => true]);
    }

    public function test_configuring_a_module_twice_keeps_exactly_one_row(): void
    {
        $site = Site::factory()->create();
        $service = app(ModuleConfigService::class);

        $service->configureModule($site, 'performance', true, 10080);
        $service->configureModule($site, 'performance', true, 10080);

        $this->assertSame(1, PerformanceMonitor::where('site_id', $site->id)->count());

        $service->configureModule($site, 'uptime', true, 5);
        $service->configureModule($site, 'uptime', true, 5);

        $this->assertSame(1, UptimeMonitor::where('site_id', $site->id)->count());
    }
}
