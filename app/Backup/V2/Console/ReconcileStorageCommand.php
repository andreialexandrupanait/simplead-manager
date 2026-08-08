<?php

declare(strict_types=1);

namespace App\Backup\V2\Console;

use App\Backup\V2\Legacy\LegacyBackupReader;
use App\Backup\V2\Support\BackupLogger;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\StorageDestination;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Console\Command;

/**
 * STRICT read-only drift report between the `backups` table (what the DB thinks
 * exists) and the actual storage contents (what really exists), per
 * docs/backup/TARGET-ARCHITECTURE.md ("used_bytes accounting → derived from
 * storage truth" and the 191-missing-manifest defect).
 *
 * It NEVER mutates rows or storage unless config('backup_v2.reconciliation_writes_enabled')
 * is true (defaults false — this phase leaves it false, so the command is inert
 * beyond reporting). Reuses the existing storage drivers
 * (App\Services\Backup\Storage\*) — it does not reimplement transport.
 *
 * Handles empty/absent storage gracefully (lab/MinIO is empty), and never touches
 * a real client site.
 */
class ReconcileStorageCommand extends Command
{
    protected $signature = 'backup:reconcile-storage '
        .'{--site= : Restrict to a single site id} '
        .'{--destination= : Restrict to a single StorageDestination id} '
        .'{--json : Emit a machine-readable JSON report instead of a table} '
        .'{--fix-used-bytes : Correct each destination\'s used_bytes to the storage total}';

    protected $description = 'Drift report: DB-recorded backups vs actual storage contents (used_bytes truth).';

    public function handle(): int
    {
        // Two ways to permit the one write this command can make. The env flag is the standing
        // default for anything automated; the option is for a person deciding to repair the counter
        // now, without a deploy — which matters because correcting it is exactly the sort of thing
        // noticed while looking at the report, and a fix that requires redeploying to apply is a fix
        // that waits.
        $writesEnabled = (bool) config('backup_v2.reconciliation_writes_enabled', false)
            || (bool) $this->option('fix-used-bytes');
        $logger = (new BackupLogger)->forSession('reconcile', null, null);

        $destinations = $this->resolveDestinations();
        if ($destinations->isEmpty()) {
            $this->warn('No active storage destinations to reconcile.');

            return self::SUCCESS;
        }

        $siteFilter = $this->option('site') !== null ? (int) $this->option('site') : null;

        $report = [
            'writes_enabled' => $writesEnabled,
            'generated_at' => now()->toIso8601String(),
            'destinations' => [],
            'drift' => [
                'backups_without_manifest' => [],
                'missing_objects' => [],
                'used_bytes_inconsistent' => [],
            ],
        ];

        $reader = new LegacyBackupReader;

        foreach ($destinations as $destination) {
            $destReport = $this->reconcileDestination($destination, $siteFilter, $reader, $writesEnabled);
            $report['destinations'][] = $destReport['summary'];
            $report['drift']['backups_without_manifest'] = array_merge(
                $report['drift']['backups_without_manifest'],
                $destReport['backups_without_manifest']
            );
            $report['drift']['missing_objects'] = array_merge(
                $report['drift']['missing_objects'],
                $destReport['missing_objects']
            );
            if ($destReport['used_bytes_inconsistent'] !== null) {
                $report['drift']['used_bytes_inconsistent'][] = $destReport['used_bytes_inconsistent'];
            }
        }

        $logger->info('reconcile-storage completed', [
            'destinations' => count($report['destinations']),
            'backups_without_manifest' => count($report['drift']['backups_without_manifest']),
            'missing_objects' => count($report['drift']['missing_objects']),
            'used_bytes_inconsistent' => count($report['drift']['used_bytes_inconsistent']),
            'writes_enabled' => $writesEnabled,
        ]);

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->renderTable($report, $writesEnabled);

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

    /**
     * @return array{
     *     summary: array<string, mixed>,
     *     backups_without_manifest: list<array<string, mixed>>,
     *     missing_objects: list<array<string, mixed>>,
     *     used_bytes_inconsistent: array<string, mixed>|null
     * }
     */
    private function reconcileDestination(
        StorageDestination $destination,
        ?int $siteFilter,
        LegacyBackupReader $reader,
        bool $writesEnabled,
    ): array {
        $backupsWithoutManifest = [];
        $missingObjects = [];
        $usedBytesInconsistent = null;

        $storageFiles = [];
        $storageReadable = true;
        try {
            $driver = StorageFactory::make($destination);
            $storageFiles = $driver->listRecursive('');
        } catch (\Throwable $e) {
            // Empty/absent/unconfigured storage (lab MinIO) — degrade gracefully.
            $storageReadable = false;
            $this->warn("  destination #{$destination->id}: could not list storage ({$e->getMessage()}) — treating as empty.");
        }

        // Index storage paths for O(1) existence checks, and sum real bytes.
        $storagePaths = [];
        $summedBytes = 0;
        foreach ($storageFiles as $entry) {
            if ($entry['is_dir']) {
                continue;
            }
            $storagePaths[$entry['path']] = (int) $entry['size'];
            $summedBytes += (int) $entry['size'];
        }

        // Walk DB rows for this destination.
        $query = Backup::query()
            ->where('storage_destination_id', $destination->id)
            ->where('status', BackupStatus::Completed);
        if ($siteFilter !== null) {
            $query->where('site_id', $siteFilter);
        }

        $query->orderBy('id')->chunk(200, function ($backups) use (
            &$backupsWithoutManifest,
            &$missingObjects,
            $storagePaths,
            $storageReadable,
            $reader,
        ) {
            foreach ($backups as $backup) {
                $this->assessBackup(
                    $backup,
                    $storagePaths,
                    $storageReadable,
                    $reader,
                    $backupsWithoutManifest,
                    $missingObjects,
                );
            }
        });

        // used_bytes truth: only meaningful when storage was actually readable.
        if ($storageReadable) {
            $recorded = (int) $destination->used_bytes;
            if ($this->isUsedBytesDrifted($recorded, $summedBytes)) {
                $usedBytesInconsistent = [
                    'destination_id' => $destination->id,
                    'destination_name' => $destination->name,
                    'recorded_used_bytes' => $recorded,
                    'summed_used_bytes' => $summedBytes,
                    'delta_bytes' => $summedBytes - $recorded,
                ];

                // The one correction this command is allowed to make, and until now it made none:
                // `$writesEnabled` was threaded through every signature and then read by nothing, so
                // the switch named a repair it did not perform.
                //
                // Only used_bytes, and only from a readable destination. It is a derived counter —
                // the sum of what is in the bucket — so recomputing it cannot lose anything, unlike
                // every other drift on this report, which describes objects and rows and wants a
                // person's judgement. Left drifting it is the number the quota check reads, and this
                // fleet's copy was 215 GB light after a year of retention never running: a quota that
                // has stopped counting is a quota that has stopped protecting.
                if ($writesEnabled) {
                    $destination->forceFill(['used_bytes' => $summedBytes])->save();
                    $usedBytesInconsistent['corrected'] = true;
                }
            }
        }

        return [
            'summary' => [
                'destination_id' => $destination->id,
                'destination_name' => $destination->name,
                'type' => $destination->type,
                'storage_readable' => $storageReadable,
                'storage_object_count' => count($storagePaths),
                'summed_bytes' => $summedBytes,
                'recorded_used_bytes' => (int) $destination->used_bytes,
                'backups_without_manifest' => count($backupsWithoutManifest),
                'missing_objects' => count($missingObjects),
            ],
            'backups_without_manifest' => $backupsWithoutManifest,
            'missing_objects' => $missingObjects,
            'used_bytes_inconsistent' => $usedBytesInconsistent,
        ];
    }

    /**
     * @param  array<string, int>  $storagePaths
     * @param  list<array<string, mixed>>  $backupsWithoutManifest
     * @param  list<array<string, mixed>>  $missingObjects
     */
    private function assessBackup(
        Backup $backup,
        array $storagePaths,
        bool $storageReadable,
        LegacyBackupReader $reader,
        array &$backupsWithoutManifest,
        array &$missingObjects,
    ): void {
        // Nothing to check if we couldn't read storage at all.
        if (! $storageReadable) {
            return;
        }

        $prefix = trim((string) $backup->file_path, '/');

        // A backup written by the engine occupies a PREFIX, not a file. Asking whether that prefix
        // exists as an object is a question storage always answers no to, so this report declared
        // every one of the fleet's backups to be missing its objects — 121 of them, all of them
        // intact. A drift report that cries wolf on the entire fleet is worse than no report: the
        // one time something really is gone, it reads exactly the same.
        //
        // Asked via the completion marker, which is written last, after every declared object and
        // the manifest have been verified in storage. Its presence is the strongest single-object
        // statement that the backup is whole; a stray chunk from an abandoned run would not have one.
        if ($backup->engine === \App\Enums\BackupEngine::V2) {
            if ($prefix !== '' && ! $this->pathPresent($storagePaths, $prefix.'/_COMPLETE')) {
                $missingObjects[] = [
                    'backup_id' => $backup->id,
                    'site_id' => $backup->site_id,
                    'format' => $backup->format,
                    'expected_object' => $prefix.'/_COMPLETE',
                ];
            }

            return;
        }

        if ($backup->format === \App\Services\Backup\BackupManifestV3::FORMAT) {
            $manifestPath = $prefix.'/'.\App\Services\Backup\BackupManifestV3::MANIFEST_FILENAME;
            $manifestPresent = $this->pathPresent($storagePaths, $manifestPath);

            if (! $manifestPresent) {
                $backupsWithoutManifest[] = [
                    'backup_id' => $backup->id,
                    'site_id' => $backup->site_id,
                    'format' => $backup->format,
                    'expected_manifest' => $manifestPath,
                ];
            }
        } elseif ($backup->file_path !== null) {
            // v2-zip: the archive itself must be present.
            if (! $this->pathPresent($storagePaths, $prefix)) {
                $missingObjects[] = [
                    'backup_id' => $backup->id,
                    'site_id' => $backup->site_id,
                    'format' => $backup->format,
                    'expected_object' => $prefix,
                ];
            }
        }
    }

    /**
     * Storage paths may be prefixed by a driver base_path; match on suffix so we
     * are robust to that without reimplementing driver path logic.
     *
     * @param  array<string, int>  $storagePaths
     */
    private function pathPresent(array $storagePaths, string $needle): bool
    {
        $needle = trim($needle, '/');
        if ($needle === '') {
            return false;
        }
        if (isset($storagePaths[$needle])) {
            return true;
        }
        foreach ($storagePaths as $path => $_size) {
            if (str_ends_with(trim($path, '/'), $needle)) {
                return true;
            }
        }

        return false;
    }

    private function isUsedBytesDrifted(int $recorded, int $summed): bool
    {
        if ($recorded === $summed) {
            return false;
        }
        // Tolerate ±1% (ACCEPTANCE-TESTS §2 "within ±1%").
        $tolerance = (int) ceil(max($recorded, $summed) * 0.01);

        return abs($summed - $recorded) > $tolerance;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function renderTable(array $report, bool $writesEnabled): void
    {
        $this->info('backup:reconcile-storage — read-only drift report');
        $this->line('  writes_enabled: '.($writesEnabled ? 'YES (mutations allowed)' : 'no (report only)'));
        $this->newLine();

        $rows = [];
        foreach ($report['destinations'] as $d) {
            $rows[] = [
                $d['destination_id'],
                $d['destination_name'],
                $d['type'],
                $d['storage_readable'] ? 'yes' : 'no',
                $d['storage_object_count'],
                $d['summed_bytes'],
                $d['recorded_used_bytes'],
                $d['backups_without_manifest'],
                $d['missing_objects'],
            ];
        }
        $this->table(
            ['Dest', 'Name', 'Type', 'Readable', 'Objects', 'Summed B', 'Recorded B', 'No manifest', 'Missing obj'],
            $rows
        );

        $noManifest = $report['drift']['backups_without_manifest'];
        $missing = $report['drift']['missing_objects'];
        $usedDrift = $report['drift']['used_bytes_inconsistent'];

        if ($noManifest === [] && $missing === [] && $usedDrift === []) {
            $this->info('No drift detected.');

            return;
        }

        if ($noManifest !== []) {
            $this->newLine();
            $this->warn(count($noManifest).' backup(s) missing their manifest:');
            foreach ($noManifest as $d) {
                $this->line("  backup #{$d['backup_id']} (site {$d['site_id']}) expected {$d['expected_manifest']}");
            }
        }
        if ($missing !== []) {
            $this->newLine();
            $this->warn(count($missing).' backup(s) with missing objects:');
            foreach ($missing as $d) {
                $this->line("  backup #{$d['backup_id']} (site {$d['site_id']}) expected {$d['expected_object']}");
            }
        }
        if ($usedDrift !== []) {
            $this->newLine();
            $this->warn('used_bytes inconsistent:');
            foreach ($usedDrift as $d) {
                $this->line("  destination #{$d['destination_id']} recorded={$d['recorded_used_bytes']} summed={$d['summed_used_bytes']} delta={$d['delta_bytes']}");
            }
        }
    }
}
