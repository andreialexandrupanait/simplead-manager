<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\AuditStatus;
use App\Jobs\Audit\RunSfCrawl;
use App\Models\Audit;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Faza D5: monitoring — re-audit targets that already have a prior audit, so the
 * new run can be delta'd against the previous one (AuditDeltaService).
 *
 * Deliberately opt-in / manual: this is NOT registered on the scheduler. Enabling
 * a recurring cadence (and its fleet scope + cost) is a product decision — add a
 * schedule entry (e.g. `->command('audit:monitor --all')->monthly()`) once agreed.
 */
class MonitorAudits extends Command
{
    protected $signature = 'audit:monitor
        {--site= : Re-audit the site with this id}
        {--prospect= : Re-audit the prospect with this id}
        {--all : Re-audit every target that already has an audit}';

    protected $description = 'Queue re-audits for monitoring (delta vs. the previous run).';

    public function handle(): int
    {
        $baselines = $this->resolveBaselines();
        if ($baselines->isEmpty()) {
            $this->warn('No targets to monitor (need at least one existing audit; pass --site, --prospect or --all).');

            return self::SUCCESS;
        }

        $queued = 0;
        foreach ($baselines as $baseline) {
            $audit = Audit::query()->create([
                'site_id' => $baseline->site_id,
                'prospect_id' => $baseline->prospect_id,
                'url' => $baseline->url,
                'status' => AuditStatus::Configurat,
                'methodology_version' => $baseline->methodology_version,
            ]);
            RunSfCrawl::dispatch($audit->id);
            $queued++;
            $this->line("Queued re-audit #{$audit->id} for {$audit->url}");
        }

        $this->info("Monitoring: queued {$queued} re-audit(s).");

        return self::SUCCESS;
    }

    /**
     * The latest existing audit per target to re-audit (the delta baseline).
     *
     * @return Collection<int, Audit>
     */
    private function resolveBaselines(): Collection
    {
        $site = $this->option('site');
        if ($site !== null) {
            return Audit::query()->where('site_id', (int) $site)->latest('id')->limit(1)->get();
        }
        $prospect = $this->option('prospect');
        if ($prospect !== null) {
            return Audit::query()->where('prospect_id', (int) $prospect)->latest('id')->limit(1)->get();
        }
        if ($this->option('all')) {
            $latestIds = Audit::query()->selectRaw('MAX(id) as id')->groupBy('site_id', 'prospect_id')->pluck('id');

            return Audit::query()->whereIn('id', $latestIds)->get();
        }

        return new Collection;
    }
}
