<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\DnsChange;
use App\Models\DnsMonitor;
use App\Models\PhpErrorLog;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Services\ReportChartService;
use App\Services\ReportDataGatherer;
use App\Services\Reports\Sections\DnsGatherer;
use App\Services\Reports\Sections\ErrorLogGatherer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2-01: the DnsGatherer / ErrorLogGatherer are keyed 'dns' / 'error_logs' — not
 * template section keys — so the ReportDataGatherer loop never invoked them and
 * the Infrastructure section could never render. This asserts they are now wired
 * whenever the template includes 'infrastructure'.
 */
class ReportInfrastructureSectionTest extends TestCase
{
    use RefreshDatabase;

    private function gather(Site $site, array $sections): array
    {
        $template = ReportTemplate::factory()->create(['sections' => $sections]);
        $periodEnd = Carbon::today();
        $periodStart = $periodEnd->copy()->subDays(30);

        $gatherer = new ReportDataGatherer(
            $site,
            $template,
            $periodStart,
            $periodEnd,
            null,
            null,
            new ReportChartService,
            'en',
            [new DnsGatherer, new ErrorLogGatherer],
        );

        return $gatherer->gather([]);
    }

    public function test_infrastructure_populates_dns_and_error_log_data(): void
    {
        $site = Site::factory()->create();

        $monitor = DnsMonitor::create([
            'site_id' => $site->id,
            'domain' => 'example.com',
            'is_active' => true,
            'interval_minutes' => 60,
            'current_records' => [
                'A' => ['1.2.3.4'],
                'TXT' => ['v=spf1 include:_spf.google.com ~all'],
                'DMARC' => ['v=DMARC1; p=reject'],
            ],
        ]);

        DnsChange::create([
            'dns_monitor_id' => $monitor->id,
            'record_type' => 'A',
            'old_value' => '1.1.1.1',
            'new_value' => '1.2.3.4',
            'detected_at' => now()->subDays(2),
        ]);

        PhpErrorLog::create([
            'site_id' => $site->id,
            'level' => 'fatal',
            'message' => 'Uncaught TypeError in theme.php',
            'message_hash' => md5('err-1'),
            'count' => 4,
            'first_seen_at' => now()->subDays(3),
            'last_seen_at' => now()->subDays(1),
            'is_resolved' => false,
        ]);

        $data = $this->gather($site, ['overview', 'infrastructure']);

        $this->assertArrayHasKey('dns', $data);
        $this->assertNotNull($data['dns']);
        $this->assertTrue($data['dns']['available']);
        $this->assertSame('example.com', $data['dns']['domain']);
        $this->assertTrue($data['dns']['has_spf']);
        $this->assertTrue($data['dns']['has_dmarc']);
        $this->assertSame(1, $data['dns']['changes_count']);

        $this->assertArrayHasKey('error_logs', $data);
        $this->assertNotNull($data['error_logs']);
        $this->assertTrue($data['error_logs']['available']);
        $this->assertSame(1, $data['error_logs']['total_count']);
        $this->assertSame(1, $data['error_logs']['fatal_count']);
        $this->assertNotEmpty($data['error_logs']['top_errors']);
    }

    public function test_infrastructure_not_gathered_when_section_excluded(): void
    {
        $site = Site::factory()->create();

        $data = $this->gather($site, ['overview']);

        $this->assertArrayNotHasKey('dns', $data);
        $this->assertArrayNotHasKey('error_logs', $data);
    }
}
