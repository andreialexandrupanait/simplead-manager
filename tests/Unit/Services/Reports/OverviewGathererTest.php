<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Reports;

use App\Models\Backup;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\SiteMonthlySnapshot;
use App\Models\StorageDestination;
use App\Models\UpdateLog;
use App\Services\ReportChartService;
use App\Services\Reports\Sections\OverviewGatherer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class OverviewGathererTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Carbon $periodStart;

    private Carbon $periodEnd;

    private ReportChartService $chartService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create([
            'health_score' => 85,
        ]);
        SiteHealthState::create(['site_id' => $this->site->id, 'circuit_state' => 'closed']);

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
            'uptime_percentage' => 99.9,
            'uptime_avg_response_ms' => 280.0,
            'uptime_incidents_count' => 0,
            'uptime_down_checks' => 0,
            'backups_total' => 0,
            'backups_successful' => 0,
            'backups_failed' => 0,
            'updates_applied' => 0,
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
    public function supports_overview_section(): void
    {
        $gatherer = new OverviewGatherer;

        $this->assertTrue($gatherer->supports('overview'));
        $this->assertFalse($gatherer->supports('uptime'));
    }

    #[Test]
    public function returns_data_with_expected_keys(): void
    {
        $gatherer = new OverviewGatherer;

        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertArrayHasKey('updates', $result);
        $this->assertArrayHasKey('uptime', $result);
        $this->assertArrayHasKey('backups', $result);
        $this->assertArrayHasKey('performance', $result);
        $this->assertArrayHasKey('security', $result);
    }

    #[Test]
    public function counts_updates_in_period(): void
    {
        UpdateLog::create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'name' => 'woocommerce',
            'from_version' => '8.0',
            'to_version' => '8.1',
            'success' => true,
            'performed_at' => $this->periodStart->copy()->addDays(5),
        ]);
        UpdateLog::create([
            'site_id' => $this->site->id,
            'type' => 'theme',
            'name' => 'storefront',
            'from_version' => '4.0',
            'to_version' => '4.1',
            'success' => true,
            'performed_at' => $this->periodStart->copy()->addDays(10),
        ]);

        $gatherer = new OverviewGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(['updates_applied' => null]), null, $this->chartService, 'en',
        );

        $this->assertEquals(2, $result['updates']['count']);
    }

    #[Test]
    public function counts_successful_backups_in_period(): void
    {
        $destination = StorageDestination::factory()->create();

        Backup::factory()->for($this->site)->for($destination)->create([
            'status' => 'completed',
            'created_at' => $this->periodStart->copy()->addDays(3),
        ]);
        Backup::factory()->for($this->site)->for($destination)->create([
            'status' => 'failed',
            'created_at' => $this->periodStart->copy()->addDays(7),
        ]);

        $gatherer = new OverviewGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(['backups_successful' => null]), null, $this->chartService, 'en',
        );

        $this->assertEquals(1, $result['backups']['successful']);
    }

    #[Test]
    public function returns_zero_counts_when_no_data(): void
    {
        $gatherer = new OverviewGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(['updates_applied' => null, 'backups_successful' => null]), null, $this->chartService, 'en',
        );

        $this->assertEquals(0, $result['updates']['count']);
        $this->assertEquals(0, $result['backups']['successful']);
    }
}
