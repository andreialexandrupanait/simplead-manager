<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\GenerateReport;
use App\Models\Report;
use App\Models\ReportTemplate;
use App\Models\Site;
use App\Models\SiteHealthState;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GenerateReportTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private ReportTemplate $template;

    private Carbon $periodStart;

    private Carbon $periodEnd;

    protected function setUp(): void
    {
        parent::setUp();

        $this->site = Site::factory()->create();

        SiteHealthState::create([
            'site_id' => $this->site->id,
            'circuit_state' => 'closed',
        ]);

        $this->template = ReportTemplate::factory()->create();
        $this->periodStart = now()->subMonth()->startOfMonth();
        $this->periodEnd = now()->subMonth()->endOfMonth();
    }

    #[Test]
    public function report_record_created_before_generation(): void
    {
        // The job creates a Report record before calling the generator.
        // Without mocking the generator, it will fail — but the record should exist.
        $job = new GenerateReport(
            $this->site,
            $this->template,
            $this->periodStart,
            $this->periodEnd,
        );

        $job->handle();

        $report = Report::where('site_id', $this->site->id)->first();
        $this->assertNotNull($report, 'Report record should be created before generation starts');
        $this->assertEquals($this->template->id, $report->report_template_id);
        $this->assertEquals('manual', $report->trigger);
    }

    #[Test]
    public function report_status_is_terminal_after_handle(): void
    {
        $job = new GenerateReport(
            $this->site,
            $this->template,
            $this->periodStart,
            $this->periodEnd,
        );

        $job->handle();

        $report = Report::where('site_id', $this->site->id)->first();
        $this->assertNotNull($report);
        // Status should be terminal — either completed or failed, never stuck at 'generating'
        $this->assertContains($report->status->value, ['completed', 'failed']);
    }

    #[Test]
    public function report_title_contains_site_name_and_date(): void
    {
        $job = new GenerateReport(
            $this->site,
            $this->template,
            $this->periodStart,
            $this->periodEnd,
        );

        $job->handle();

        $report = Report::where('site_id', $this->site->id)->first();
        $this->assertStringContainsString($this->site->name, $report->title);
        $this->assertStringContainsString($this->periodEnd->format('d.m.Y'), $report->title);
    }

    #[Test]
    public function report_preserves_trigger_type(): void
    {
        $job = new GenerateReport(
            site: $this->site,
            template: $this->template,
            periodStart: $this->periodStart,
            periodEnd: $this->periodEnd,
            trigger: 'scheduled',
        );

        $job->handle();

        $report = Report::where('site_id', $this->site->id)->first();
        $this->assertEquals('scheduled', $report->trigger);
    }

    #[Test]
    public function report_links_to_schedule_when_provided(): void
    {
        $schedule = \App\Models\ReportSchedule::factory()->create([
            'site_id' => $this->site->id,
            'report_template_id' => $this->template->id,
        ]);

        $job = new GenerateReport(
            site: $this->site,
            template: $this->template,
            periodStart: $this->periodStart,
            periodEnd: $this->periodEnd,
            schedule: $schedule,
        );

        $job->handle();

        $report = Report::where('site_id', $this->site->id)->first();
        $this->assertEquals($schedule->id, $report->report_schedule_id);
    }

    #[Test]
    public function report_dispatched_on_reports_queue(): void
    {
        Queue::fake();

        GenerateReport::dispatch(
            $this->site,
            $this->template,
            $this->periodStart,
            $this->periodEnd,
        );

        Queue::assertPushedOn('reports', GenerateReport::class);
    }

    #[Test]
    public function report_unique_id_uses_site_and_template(): void
    {
        $job = new GenerateReport(
            $this->site,
            $this->template,
            $this->periodStart,
            $this->periodEnd,
        );

        $this->assertSame(
            'report-'.$this->site->id.'-'.$this->template->id,
            $job->uniqueId()
        );
    }

    #[Test]
    public function report_has_correct_job_properties(): void
    {
        $job = new GenerateReport(
            $this->site,
            $this->template,
            $this->periodStart,
            $this->periodEnd,
        );

        $this->assertSame(300, $job->timeout);
        $this->assertSame(512, $job->memory);
        $this->assertSame(2, $job->tries);
    }

    #[Test]
    public function failed_method_does_not_throw(): void
    {
        $job = new GenerateReport(
            $this->site,
            $this->template,
            $this->periodStart,
            $this->periodEnd,
        );

        // Should not throw even with an exception
        $job->failed(new \RuntimeException('Gotenberg unavailable'));

        $this->assertTrue(true);
    }

    #[Test]
    public function multiple_reports_can_be_created_for_same_site(): void
    {
        $job1 = new GenerateReport(
            $this->site,
            $this->template,
            $this->periodStart,
            $this->periodEnd,
        );
        $job1->handle();

        $job2 = new GenerateReport(
            $this->site,
            $this->template,
            now()->subMonths(2)->startOfMonth(),
            now()->subMonths(2)->endOfMonth(),
        );
        $job2->handle();

        $this->assertEquals(2, Report::where('site_id', $this->site->id)->count());
    }
}
