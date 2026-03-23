<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Report;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MigrateReportFiles extends Command
{
    protected $signature = 'reports:migrate-files {--dry-run : Show what would be done without making changes}';

    protected $description = 'Migrate existing report files to the new naming/folder structure';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        if ($dryRun) {
            $this->info('DRY RUN — no files will be moved.');
        }

        $reports = Report::whereNotNull('file_path')
            ->where('status', 'completed')
            ->with('site')
            ->get();

        $this->info("Found {$reports->count()} completed reports with files.");

        $moved = 0;
        $skipped = 0;
        $missing = 0;

        foreach ($reports as $report) {
            $site = $report->site;

            if (! $site) {
                $this->warn("  SKIP report #{$report->id} — site not found.");
                $skipped++;

                continue;
            }

            if (! $report->period_end) {
                $this->warn("  SKIP report #{$report->id} — no period_end date.");
                $skipped++;

                continue;
            }

            $clientName = $site->name;
            $safeName = Str::slug($clientName);
            $periodEnd = $report->period_end;
            $month = $periodEnd->format('m');
            $year = $periodEnd->format('Y');
            $date = $periodEnd->format('d.m.Y');

            $newDirectory = 'reports/'.$safeName.'/'.$year;
            $newFileName = $month.'. Maintenance Report | '.$clientName.' - '.$date.'.pdf';
            $newPath = $newDirectory.'/'.$newFileName;

            $oldPath = $report->file_path;

            // Already at the correct path
            if ($oldPath === $newPath) {
                $this->line("  OK report #{$report->id} — already at correct path.");
                $skipped++;

                continue;
            }

            // Check if old file exists
            if (! Storage::disk('local')->exists($oldPath)) {
                $this->warn("  MISSING report #{$report->id} — file not found: {$oldPath}");
                $missing++;

                continue;
            }

            if ($dryRun) {
                $this->line("  WOULD MOVE report #{$report->id}:");
                $this->line("    FROM: {$oldPath}");
                $this->line("    TO:   {$newPath}");
                $moved++;

                continue;
            }

            // Create directory and move file
            Storage::disk('local')->makeDirectory($newDirectory);
            Storage::disk('local')->move($oldPath, $newPath);

            // Update DB
            $report->update([
                'file_path' => $newPath,
                'file_name' => $newFileName,
            ]);

            $this->line("  MOVED report #{$report->id}: {$oldPath} → {$newPath}");
            $moved++;

            // Clean up old directory if empty
            $oldDirectory = dirname($oldPath);
            $remaining = Storage::disk('local')->files($oldDirectory);
            if (empty($remaining)) {
                Storage::disk('local')->deleteDirectory($oldDirectory);
                $this->line("    Removed empty directory: {$oldDirectory}");
            }
        }

        $this->newLine();
        $verb = $dryRun ? 'Would move' : 'Moved';
        $this->info("{$verb}: {$moved} | Skipped: {$skipped} | Missing: {$missing}");

        return self::SUCCESS;
    }
}
