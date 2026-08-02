<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\BackupConfig;
use App\Models\Site;
use App\Services\Backup\RetentionService;
use Illuminate\Console\Command;

/**
 * What retention WOULD delete, as a table you can read.
 *
 * Whether retention actually deletes is a setting on the data retention screen (default
 * true) whose whole purpose is to produce evidence before anyone turns deletion
 * on. It writes that evidence through Log::info — and production runs at
 * LOG_LEVEL=warning, so it has been writing to nowhere. Months of "safe
 * rollout" produced zero observations; grepping five days of logs returns
 * nothing at all.
 *
 * This command answers the same question directly instead of hoping a log level
 * cooperates. It is STRICTLY read-only: it never deletes, never writes, and does
 * not care what the dry-run flag is set to. It reuses RetentionService's own
 * chain building so the report cannot drift from the behaviour it predicts —
 * a report that models retention separately would eventually describe a
 * retention that does not exist.
 */
class RetentionReportCommand extends Command
{
    protected $signature = 'backup:retention-report '
        .'{--site= : Restrict to a single site id} '
        .'{--json : Emit a machine-readable report instead of a table}';

    protected $description = 'Read-only: what the configured retention policy would delete, per site.';

    public function handle(RetentionService $retention): int
    {
        $configs = BackupConfig::query()
            ->when($this->option('site'), fn ($q) => $q->where('site_id', (int) $this->option('site')))
            ->with('site')
            ->get()
            ->filter(fn (BackupConfig $c) => $c->site !== null);

        if ($configs->isEmpty()) {
            $this->warn('No backup configuration matched.');

            return self::SUCCESS;
        }

        $rows = [];
        $totalDeleted = 0;
        $totalBytes = 0;

        foreach ($configs->sortBy('site_id') as $config) {
            $report = $this->reportFor($retention, $config);
            $rows[] = $report;
            $totalDeleted += $report['would_delete'];
            $totalBytes += $report['would_free_bytes'];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'generated_at' => now()->toIso8601String(),
                'retention_dry_run' => ! app(\App\Services\Backup\BackupRetentionSettings::class)->deletesEnabled(),
                'totals' => ['backups' => $totalDeleted, 'bytes' => $totalBytes],
                'sites' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['Site', 'Enabled', 'Policy', 'Total', 'Would delete', 'Would free', 'Keeps', 'Oldest kept', 'Guard'],
            array_map(fn (array $r) => [
                $r['site_id'].' '.$r['url'],
                $r['enabled'] ? 'yes' : 'NO',
                $r['policy'],
                $r['total'],
                $r['would_delete'],
                $this->humanBytes($r['would_free_bytes']),
                $r['would_keep'],
                $r['oldest_kept'] ?? '—',
                $r['guard_fired'] ? 'KEPT LAST' : '',
            ], $rows),
        );

        $this->newLine();
        $this->line(sprintf(
            'Would delete %d backups, freeing %s. Retention is currently %s.',
            $totalDeleted,
            $this->humanBytes($totalBytes),
            app(\App\Services\Backup\BackupRetentionSettings::class)->deletesEnabled() ? 'LIVE' : 'DRY-RUN (deletes nothing)',
        ));

        $guarded = array_filter($rows, fn (array $r) => $r['guard_fired']);
        if ($guarded !== []) {
            $this->newLine();
            $this->warn(sprintf(
                '%d site(s) would have lost every restore point; the newest chain was kept: %s',
                count($guarded),
                implode(', ', array_map(fn (array $r) => $r['url'], $guarded)),
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return array<string, mixed>
     */
    private function reportFor(RetentionService $retention, BackupConfig $config): array
    {
        /** @var Site $site */
        $site = $config->site;

        $backups = Backup::where('site_id', $site->id)
            ->where('status', BackupStatus::Completed)
            ->where('is_locked', false)
            ->orderByDesc('created_at')
            ->get();

        $chains = $retention->planChains($backups);
        $doomed = $retention->planExpiredChains($chains, $config);

        $guardFired = $chains !== [] && count($doomed) === count($chains);
        if ($guardFired) {
            array_shift($doomed);
        }

        $doomedBackups = collect($doomed)->flatten(1);
        $keptIds = $backups->pluck('id')->diff($doomedBackups->pluck('id'));

        return [
            'site_id' => $site->id,
            'url' => (string) $site->url,
            'enabled' => (bool) $config->is_enabled,
            'policy' => $config->retention_type.'='.$config->retention_value,
            'total' => $backups->count(),
            'would_delete' => $doomedBackups->count(),
            'would_free_bytes' => (int) $doomedBackups->sum('file_size'),
            'would_keep' => $keptIds->count(),
            'oldest_kept' => $backups->whereIn('id', $keptIds)->min('created_at')?->toDateString(),
            'guard_fired' => $guardFired,
        ];
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes < 1024 ** 3) {
            return number_format($bytes / (1024 ** 2), 1).' MB';
        }

        return number_format($bytes / (1024 ** 3), 1).' GB';
    }
}
