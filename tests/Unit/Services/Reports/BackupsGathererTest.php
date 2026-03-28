<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Reports;

use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\SiteMonthlySnapshot;
use App\Models\StorageDestination;
use App\Services\ReportChartService;
use App\Services\Reports\Sections\BackupsGatherer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class BackupsGathererTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Carbon $periodStart;

    private Carbon $periodEnd;

    private ReportChartService $chartService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();
        SiteHealthState::create(['site_id' => $this->site->id, 'circuit_state' => 'closed']);

        // BackupsGatherer always reads $site->backupConfig->is_enabled, so a config is required.
        BackupConfig::factory()->for($this->site)->create([
            'is_enabled' => true,
            'frequency' => 'daily',
            'type' => 'full',
        ]);

        $this->periodStart = now()->subMonth()->startOfMonth();
        $this->periodEnd = now()->subMonth()->endOfMonth();
        $this->chartService = new ReportChartService;
    }

    private function createSnapshot(?array $overrides = []): SiteMonthlySnapshot
    {
        return SiteMonthlySnapshot::create(array_merge([
            'site_id' => $this->site->id,
            'year' => (int) $this->periodStart->format('Y'),
            'month' => (int) $this->periodStart->format('n'),
            'uptime_percentage' => null,
            'uptime_avg_response_ms' => null,
            'uptime_incidents_count' => null,
            'uptime_down_checks' => null,
            'backups_total' => 0,
            'backups_successful' => 0,
            'backups_failed' => 0,
            'updates_applied' => null,
            'security_avg_score' => null,
            'performance_avg_desktop' => null,
            'performance_avg_mobile' => null,
            'analytics_users' => null,
            'analytics_sessions' => null,
            'analytics_pageviews' => null,
            'search_console_clicks' => null,
            'search_console_impressions' => null,
            'search_console_avg_position' => null,
            'cloudflare_requests' => null,
            'cloudflare_bandwidth_bytes' => null,
            'cloudflare_cache_hit_ratio' => null,
        ], $overrides));
    }

    #[Test]
    public function supports_backups_section(): void
    {
        $gatherer = new BackupsGatherer;

        $this->assertTrue($gatherer->supports('backups'));
        $this->assertFalse($gatherer->supports('uptime'));
    }

    #[Test]
    public function returns_data_with_expected_keys(): void
    {
        $gatherer = new BackupsGatherer;
        $result = $gatherer->gather(
            $this->site->fresh(), $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertArrayHasKey('count', $result);
        $this->assertArrayHasKey('failed_count', $result);
        $this->assertArrayHasKey('total_size', $result);
        $this->assertArrayHasKey('backups', $result);
    }

    #[Test]
    public function counts_backups_by_status(): void
    {
        $destination = StorageDestination::factory()->create();

        Backup::factory()->for($this->site)->for($destination)->create([
            'status' => 'completed',
            'created_at' => $this->periodStart->copy()->addDays(1),
        ]);
        Backup::factory()->for($this->site)->for($destination)->create([
            'status' => 'completed',
            'created_at' => $this->periodStart->copy()->addDays(5),
        ]);
        Backup::factory()->for($this->site)->for($destination)->create([
            'status' => 'failed',
            'created_at' => $this->periodStart->copy()->addDays(10),
        ]);

        $gatherer = new BackupsGatherer;
        $result = $gatherer->gather(
            $this->site->fresh(), $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertEquals(2, $result['count']);
        $this->assertEquals(1, $result['failed_count']);
        $this->assertCount(3, $result['backups']);
    }

    #[Test]
    public function includes_backup_config_info(): void
    {
        $gatherer = new BackupsGatherer;
        $result = $gatherer->gather(
            $this->site->fresh(), $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertTrue($result['schedule_enabled']);
        $this->assertEquals('daily', $result['frequency']);
    }

    #[Test]
    public function returns_empty_when_no_backups(): void
    {
        $gatherer = new BackupsGatherer;
        $result = $gatherer->gather(
            $this->site->fresh(), $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertEquals(0, $result['count']);
        $this->assertEquals(0, $result['failed_count']);
        $this->assertEmpty($result['backups']);
    }
}
