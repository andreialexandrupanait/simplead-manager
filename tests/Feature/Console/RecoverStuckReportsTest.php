<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RecoverStuckReportsTest extends TestCase
{
    use RefreshDatabase;

    private function staleGeneratingReport(): Report
    {
        $site = Site::factory()->create();
        $report = Report::factory()->generating()->create(['site_id' => $site->id]);

        DB::table('reports')->where('id', $report->id)->update([
            'updated_at' => now()->subMinutes(90),
        ]);

        return $report->fresh();
    }

    public function test_stuck_generating_report_is_swept_to_failed(): void
    {
        $report = $this->staleGeneratingReport();

        $this->artisan('reports:recover-stuck')->assertSuccessful();

        $fresh = $report->fresh();
        $this->assertSame(ReportStatus::Failed, $fresh->status);
        $this->assertStringContainsString('worker died', (string) $fresh->error_message);
    }

    public function test_fresh_generating_report_is_left_alone(): void
    {
        $site = Site::factory()->create();
        $report = Report::factory()->generating()->create(['site_id' => $site->id]);

        $this->artisan('reports:recover-stuck')->assertSuccessful();

        $this->assertSame(ReportStatus::Generating, $report->fresh()->status);
    }

    public function test_completed_and_failed_reports_are_untouched(): void
    {
        $site = Site::factory()->create();
        $completed = Report::factory()->completed()->create(['site_id' => $site->id]);
        $failed = Report::factory()->failed()->create(['site_id' => $site->id]);
        DB::table('reports')->whereIn('id', [$completed->id, $failed->id])->update([
            'updated_at' => now()->subMinutes(90),
        ]);

        $this->artisan('reports:recover-stuck')->assertSuccessful();

        $this->assertSame(ReportStatus::Completed, $completed->fresh()->status);
        $this->assertSame(ReportStatus::Failed, $failed->fresh()->status);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $report = $this->staleGeneratingReport();

        $this->artisan('reports:recover-stuck --dry-run')->assertSuccessful();

        $this->assertSame(ReportStatus::Generating, $report->fresh()->status);
    }
}
