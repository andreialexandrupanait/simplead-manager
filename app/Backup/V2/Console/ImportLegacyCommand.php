<?php

declare(strict_types=1);

namespace App\Backup\V2\Console;

use App\Backup\V2\Legacy\LegacyImportService;
use App\Models\StorageDestination;
use Illuminate\Console\Command;

/**
 * Read-only IMPORT of legacy backups into the V2 index (legacy_backup_index) via
 * App\Backup\V2\Legacy\LegacyImportService.
 *
 * READ-ONLY by default (dry-run): it lists storage + reconciles `backups` rows and
 * REPORTS the A–F classification without persisting anything. It NEVER moves,
 * rewrites or deletes a legacy object under any flag.
 *
 * Writing index rows is opt-in and gated: pass --apply AND config('backup_v2.enabled')
 * must be true. With the default flag off the command is inert beyond reporting, so it
 * is safe in production. Legacy RESTORE remains separately gated behind
 * config('backup_v2.legacy_restore_enabled').
 */
class ImportLegacyCommand extends Command
{
    protected $signature = 'backup:v2-import-legacy '
        .'{--destination= : Restrict to a single StorageDestination id} '
        .'{--site= : Restrict to a single site id} '
        .'{--apply : Persist index rows (also requires backup_v2.enabled); default is dry-run} '
        .'{--json : Emit a machine-readable JSON report}';

    protected $description = 'Read-only import of legacy backups into the V2 index (classify A–F; never mutates legacy objects).';

    public function handle(): int
    {
        // Writes are doubly gated: --apply AND the master V2 flag. Default = dry-run.
        $persist = (bool) $this->option('apply') && (bool) config('backup_v2.enabled', false);
        if ((bool) $this->option('apply') && ! $persist) {
            $this->warn('--apply ignored: backup_v2.enabled is false — running read-only (dry-run).');
        }

        $destinations = $this->resolveDestinations();
        if ($destinations->isEmpty()) {
            $this->warn('No active storage destinations to import.');

            return self::SUCCESS;
        }

        $siteFilter = $this->option('site') !== null ? (int) $this->option('site') : null;
        $service = new LegacyImportService;

        $report = ['persisted' => $persist, 'destinations' => []];
        foreach ($destinations as $destination) {
            $result = $service->importDestination($destination, $persist, $siteFilter);
            $report['destinations'][] = [
                'destination_id' => $result['destination_id'],
                'storage_readable' => $result['storage_readable'],
                'counts' => $result['counts'],
            ];
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info('backup:v2-import-legacy — '.($persist ? 'APPLY (index rows written)' : 'dry-run (report only)'));
        foreach ($report['destinations'] as $d) {
            $this->newLine();
            $this->line("destination #{$d['destination_id']} (readable: ".($d['storage_readable'] ? 'yes' : 'no').')');
            foreach ($d['counts'] as $classification => $count) {
                $this->line("  {$classification}: {$count}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, StorageDestination>
     */
    private function resolveDestinations(): \Illuminate\Support\Collection
    {
        if ($id = $this->option('destination')) {
            return StorageDestination::whereKey($id)->get();
        }

        return StorageDestination::where('is_active', true)->get();
    }
}
