<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Reports;

use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\SiteMonthlySnapshot;
use App\Models\UpdateLog;
use App\Services\ReportChartService;
use App\Services\Reports\Sections\UpdatesGatherer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class UpdatesGathererTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private Carbon $periodStart;

    private Carbon $periodEnd;

    private ReportChartService $chartService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create(['wp_version' => '6.5']);
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
            'uptime_percentage' => null,
            'uptime_avg_response_ms' => null,
            'uptime_incidents_count' => null,
            'uptime_down_checks' => null,
            'backups_total' => null,
            'backups_successful' => null,
            'backups_failed' => null,
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
    public function supports_updates_section(): void
    {
        $gatherer = new UpdatesGatherer;

        $this->assertTrue($gatherer->supports('updates'));
        $this->assertFalse($gatherer->supports('backups'));
    }

    #[Test]
    public function returns_data_with_expected_keys(): void
    {
        $gatherer = new UpdatesGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertArrayHasKey('wp_version', $result);
        $this->assertArrayHasKey('total_count', $result);
        $this->assertArrayHasKey('plugin_count', $result);
        $this->assertArrayHasKey('theme_count', $result);
        $this->assertArrayHasKey('core_count', $result);
        $this->assertArrayHasKey('all_updates', $result);
        $this->assertArrayHasKey('consolidated_updates', $result);
    }

    #[Test]
    public function groups_updates_by_type(): void
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
            'type' => 'plugin',
            'name' => 'yoast-seo',
            'from_version' => '21.0',
            'to_version' => '21.5',
            'success' => true,
            'performed_at' => $this->periodStart->copy()->addDays(10),
        ]);
        UpdateLog::create([
            'site_id' => $this->site->id,
            'type' => 'theme',
            'name' => 'storefront',
            'from_version' => '4.0',
            'to_version' => '4.1',
            'success' => true,
            'performed_at' => $this->periodStart->copy()->addDays(15),
        ]);

        $gatherer = new UpdatesGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertEquals(3, $result['total_count']);
        $this->assertEquals(2, $result['plugin_count']);
        $this->assertEquals(1, $result['theme_count']);
        $this->assertEquals(0, $result['core_count']);
    }

    #[Test]
    public function tracks_success_and_failure_counts(): void
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
            'type' => 'plugin',
            'name' => 'broken-plugin',
            'from_version' => '1.0',
            'to_version' => '2.0',
            'success' => false,
            'performed_at' => $this->periodStart->copy()->addDays(10),
        ]);

        $gatherer = new UpdatesGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertEquals(1, $result['success_count']);
        $this->assertEquals(1, $result['failed_count']);
    }

    #[Test]
    public function returns_empty_when_no_updates(): void
    {
        $gatherer = new UpdatesGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertEquals(0, $result['total_count']);
        $this->assertEmpty($result['all_updates']);
    }

    #[Test]
    public function includes_wp_version(): void
    {
        $gatherer = new UpdatesGatherer;
        $result = $gatherer->gather(
            $this->site, $this->periodStart, $this->periodEnd,
            $this->createSnapshot(), null, $this->chartService, 'en',
        );

        $this->assertEquals('6.5', $result['wp_version']);
    }
}
