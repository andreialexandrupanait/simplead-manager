<?php

namespace App\Console\Commands;

use App\Models\Report;
use App\Services\ReportGeneratorService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RegenerateReport extends Command
{
    protected $signature = 'report:regenerate {--site= : Site ID (default: latest report\'s site)} {--id= : Report ID to regenerate}';
    protected $description = 'Regenerate a report PDF in-place using the current template';

    public function handle(): int
    {
        if ($id = $this->option('id')) {
            $report = Report::find($id);
        } elseif ($siteId = $this->option('site')) {
            $report = Report::where('site_id', $siteId)->where('status', 'completed')->orderBy('id', 'desc')->first();
        } else {
            $report = Report::where('status', 'completed')->orderBy('id', 'desc')->first();
        }

        if (!$report) {
            $this->error('No report found.');
            return 1;
        }

        $this->info("Regenerating report #{$report->id} for site {$report->site_id}...");

        $site = $report->site;
        $template = $report->reportTemplate;
        $periodStart = Carbon::parse($report->period_start);
        $periodEnd = Carbon::parse($report->period_end);

        $service = new ReportGeneratorService($site, $template, $periodStart, $periodEnd);
        $newPath = $service->generate();

        // Delete old file
        if ($report->file_path && Storage::disk('local')->exists($report->file_path)) {
            Storage::disk('local')->delete($report->file_path);
        }

        // Update report record
        $report->update([
            'file_path' => $newPath,
            'file_size' => Storage::disk('local')->size($newPath),
            'generated_at' => now(),
        ]);

        $this->info("Done! New PDF: {$newPath}");

        return 0;
    }
}
