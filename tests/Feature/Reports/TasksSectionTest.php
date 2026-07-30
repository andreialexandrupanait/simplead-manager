<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\Site;
use App\Models\SiteTask;
use App\Services\ReportChartService;
use App\Services\Reports\BaseReportSectionGatherer;
use App\Services\Reports\Sections\TasksGatherer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * SPEC §12.2 point 3 — "sarcini finalizate din app.simplead.ro" inside
 * "Mentenanță efectuată", which §12.4 calls the only section that demonstrates
 * preventive work; the rest just say nothing broke.
 */
class TasksSectionTest extends TestCase
{
    use RefreshDatabase;

    private function gather(Site $site, ?Carbon $from = null, ?Carbon $to = null): array
    {
        return (new TasksGatherer)->gather(
            $site,
            $from ?? Carbon::create(2026, 7, 1),
            $to ?? Carbon::create(2026, 7, 31, 23, 59, 59),
            null,
            null,
            new ReportChartService,
            'ro',
        );
    }

    private function task(Site $site, array $overrides = []): SiteTask
    {
        return SiteTask::create(array_merge([
            'site_id' => $site->id,
            'external_id' => 'ext-'.uniqid('', true),
            'name' => 'Modificari homepage',
            'status' => 'completed',
            'assignee_name' => 'Andrei',
            'status_changed_at' => Carbon::create(2026, 7, 15, 10),
        ], $overrides));
    }

    public function test_completed_work_in_the_period_is_listed(): void
    {
        $site = Site::factory()->create(['external_project_id' => 'proj-1']);
        $this->task($site, ['name' => 'Optimizare viteza']);
        $this->task($site, ['name' => 'Actualizare continut']);

        $data = $this->gather($site);

        $this->assertTrue($data['available']);
        $this->assertSame(2, $data['completed_count']);
        $this->assertSame(['Optimizare viteza', 'Actualizare continut'], array_column($data['listed'], 'name'));
    }

    public function test_work_outside_the_period_is_not_counted(): void
    {
        $site = Site::factory()->create(['external_project_id' => 'proj-1']);
        $this->task($site, ['status_changed_at' => Carbon::create(2026, 6, 20)]);
        $this->task($site, ['status_changed_at' => Carbon::create(2026, 8, 3)]);

        $this->assertSame(0, $this->gather($site)['completed_count']);
    }

    public function test_unfinished_work_is_not_reported_as_done(): void
    {
        $site = Site::factory()->create(['external_project_id' => 'proj-1']);
        $this->task($site, ['status' => 'in_progress']);
        $this->task($site, ['status' => 'to_do']);
        $this->task($site, ['status' => 'canceled']);

        $this->assertSame(0, $this->gather($site)['completed_count']);
    }

    public function test_a_site_with_no_project_gets_no_section_and_is_not_a_failure(): void
    {
        $site = Site::factory()->create(['external_project_id' => null]);

        $data = $this->gather($site);

        $this->assertArrayNotHasKey('available', $data);

        // §12.3 barrier 3: not configured is a deliberate omission, so the report
        // still sends.
        $marker = $data[BaseReportSectionGatherer::INTEGRATION_KEY];
        $this->assertFalse($marker['configured']);
        $this->assertTrue($marker['ok']);
    }

    public function test_a_linked_site_with_a_quiet_month_still_renders_the_section(): void
    {
        $site = Site::factory()->create(['external_project_id' => 'proj-1']);

        $data = $this->gather($site);

        // "Nothing needed doing" is a real answer to the client, and it must not
        // hold the report the way a broken integration would.
        $this->assertTrue($data['available']);
        $this->assertSame(0, $data['completed_count']);
        $this->assertTrue($data[BaseReportSectionGatherer::INTEGRATION_KEY]['ok']);
    }

    public function test_a_long_month_is_truncated_rather_than_printed_whole(): void
    {
        $site = Site::factory()->create(['external_project_id' => 'proj-1']);

        for ($i = 0; $i < 45; $i++) {
            $this->task($site, ['name' => "Lucrare {$i}"]);
        }

        $data = $this->gather($site);

        $this->assertSame(45, $data['completed_count']);
        $this->assertCount(40, $data['listed']);
        $this->assertSame(5, $data['truncated']);
    }

    public function test_no_financial_field_can_reach_the_report(): void
    {
        $site = Site::factory()->create(['external_project_id' => 'proj-1']);
        $this->task($site);

        $row = $this->gather($site)['listed'][0];

        $this->assertSame(['name', 'type', 'assignee', 'completed_at'], array_keys($row));
    }
}
