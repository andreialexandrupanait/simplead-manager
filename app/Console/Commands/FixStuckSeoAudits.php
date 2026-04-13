<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\SeoAuditStatus;
use App\Models\SeoAudit;
use App\Models\SeoMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class FixStuckSeoAudits extends Command
{
    protected $signature = 'seo:fix-stuck-audits {--dry-run : Show what would be done without making changes}';

    protected $description = 'Mark stuck SEO audits as failed and reset monitors for immediate re-dispatch';

    public function handle(): int
    {
        $dry = $this->option('dry-run');

        $stale = SeoAudit::running()
            ->where('updated_at', '<', now()->subMinutes(30))
            ->get();

        if ($stale->isEmpty()) {
            $this->info('No stuck audits found.');
        } else {
            foreach ($stale as $audit) {
                $this->line("Audit #{$audit->id} (site {$audit->site_id}) — status: {$audit->status->value}, updated: {$audit->updated_at}");

                if (! $dry) {
                    $audit->markAs(SeoAuditStatus::Failed, 'Orphaned audit — marked failed by cleanup command');
                }
            }
            $this->info(($dry ? '[DRY RUN] Would mark' : 'Marked') . " {$stale->count()} audit(s) as failed.");
        }

        // Reset next_audit_at on affected monitors so dispatcher picks them up
        $siteIds = $stale->pluck('site_id')->unique();
        $monitors = SeoMonitor::whereIn('site_id', $siteIds)->where('is_active', true)->get();

        foreach ($monitors as $monitor) {
            $this->line("Resetting next_audit_at for monitor site_id={$monitor->site_id}");
            if (! $dry) {
                $monitor->update(['next_audit_at' => now()]);
            }
        }

        // Clear SEO-related failed_jobs
        $failedCount = DB::table('failed_jobs')
            ->where('payload', 'like', '%RunSeoAudit%')
            ->orWhere('payload', 'like', '%CrawlSitePages%')
            ->orWhere('payload', 'like', '%AnalyzeSeoPages%')
            ->orWhere('payload', 'like', '%CalculateSeoScores%')
            ->count();

        if ($failedCount > 0) {
            if (! $dry) {
                DB::table('failed_jobs')
                    ->where('payload', 'like', '%RunSeoAudit%')
                    ->orWhere('payload', 'like', '%CrawlSitePages%')
                    ->orWhere('payload', 'like', '%AnalyzeSeoPages%')
                    ->orWhere('payload', 'like', '%CalculateSeoScores%')
                    ->delete();
            }
            $this->info(($dry ? '[DRY RUN] Would clear' : 'Cleared') . " {$failedCount} failed SEO job(s).");
        } else {
            $this->info('No failed SEO jobs to clear.');
        }

        if ($dry) {
            $this->warn('Dry run complete. Re-run without --dry-run to apply changes.');
        } else {
            $this->info('Done. Dispatcher will pick up monitors on next 5-minute cycle.');
        }

        return Command::SUCCESS;
    }
}
