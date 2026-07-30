<?php

declare(strict_types=1);

namespace App\Backup\V2\Legacy;

use App\Backup\V2\Models\LegacyBackupIndexEntry;
use App\Backup\V2\Support\BackupLogger;
use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\StorageDestination;
use App\Services\Backup\BackupManifestV3;
use App\Services\Backup\BackupSidecarMetadata;
use App\Services\Backup\Storage\StorageDriver;
use App\Services\Backup\Storage\StorageFactory;
use Throwable;

/**
 * Read-only IMPORT of legacy backups into the V2 index (`legacy_backup_index`).
 *
 * It reconciles two sources of truth — the existing `backups` rows and the actual
 * storage contents (via the existing drivers) — and classifies every legacy
 * artifact per docs/backup/LEGACY-BACKUPS-DECISION.md categories A–F:
 *
 *   valid (A)                 — completed + object present + verified restore
 *   verification_required (B) — completed + object present + not yet verified
 *   incomplete (D)            — a failed/cancelled row (no whole artifact)
 *   orphaned (E)              — a metadata doc in storage with no backups row
 *   phantom (F)               — a completed row whose object/manifest is gone
 *
 * STRICTLY read-only w.r.t. legacy storage: it only LISTS and (to enrich a
 * descriptor) DOWNLOADS-to-read metadata docs. It NEVER uploads, moves, rewrites
 * or deletes a legacy object. Restoring a legacy artifact stays gated behind
 * config('backup_v2.legacy_restore_enabled'). Persisting index rows is opt-in
 * ($persist) so the command is inert (report-only) until explicitly enabled.
 *
 * Reuses LegacyBackupReader for parsing so the "valid metadata" contract is
 * single-sourced, and the same suffix-tolerant path matching as
 * ReconcileStorageCommand so a driver base_path never breaks presence checks.
 */
final class LegacyImportService
{
    private readonly LegacyBackupReader $reader;

    private readonly BackupLogger $logger;

    public function __construct(?LegacyBackupReader $reader = null, ?BackupLogger $logger = null)
    {
        $this->reader = $reader ?? new LegacyBackupReader;
        $this->logger = ($logger ?? new BackupLogger)->forSession('legacy-import', null, null);
    }

    /**
     * Import (classify + optionally index) one destination.
     *
     * @return array{
     *     destination_id:int,
     *     storage_readable:bool,
     *     persisted:bool,
     *     counts:array<string,int>,
     *     entries:list<array<string,mixed>>
     * }
     */
    public function importDestination(StorageDestination $destination, bool $persist = false, ?int $siteFilter = null): array
    {
        $storagePaths = [];
        $storageReadable = true;
        $driver = null;
        try {
            $driver = StorageFactory::make($destination);
            foreach ($driver->listRecursive('') as $entry) {
                if ($entry['is_dir']) {
                    continue;
                }
                $storagePaths[(string) $entry['path']] = (int) $entry['size'];
            }
        } catch (Throwable $e) {
            $storageReadable = false;
            $this->logger->warning('legacy-import: storage unreadable, indexing rows only', [
                'destination_id' => $destination->id,
                'error' => $e->getMessage(),
            ]);
        }

        // Every storage path that is a known legacy metadata document.
        $metadataDocs = [];
        foreach (array_keys($storagePaths) as $path) {
            $format = $this->reader->classifyPath($path);
            if ($format !== null) {
                $metadataDocs[$path] = $format;
            }
        }

        $entries = [];
        $claimedDocPaths = [];

        // 1) Walk the DB rows (completed + failed) and classify each against storage.
        $query = Backup::query()->where('storage_destination_id', $destination->id);
        if ($siteFilter !== null) {
            $query->where('site_id', $siteFilter);
        }
        foreach ($query->orderBy('id')->get() as $backup) {
            $entries[] = $this->classifyBackupRow($backup, $storagePaths, $storageReadable, $driver, $claimedDocPaths);
        }

        // 2) Any metadata doc not claimed by a row is an ORPHAN (object, no row).
        if ($storageReadable) {
            foreach ($metadataDocs as $path => $format) {
                if (isset($claimedDocPaths[$path])) {
                    continue;
                }
                $entries[] = $this->orphanEntry($path, $format, (int) ($storagePaths[$path] ?? 0), $driver);
            }
        }

        $counts = [];
        foreach ($entries as $entry) {
            $c = (string) $entry['classification'];
            $counts[$c] = ($counts[$c] ?? 0) + 1;
        }

        if ($persist) {
            foreach ($entries as $entry) {
                $this->persist($destination, $entry);
            }
        }

        $this->logger->info('legacy-import destination processed', [
            'destination_id' => $destination->id,
            'storage_readable' => $storageReadable,
            'persisted' => $persist,
            'counts' => $counts,
        ]);

        return [
            'destination_id' => (int) $destination->id,
            'storage_readable' => $storageReadable,
            'persisted' => $persist,
            'counts' => $counts,
            'entries' => $entries,
        ];
    }

    /**
     * @param  array<string,int>  $storagePaths
     * @param  array<string,bool>  $claimedDocPaths
     * @return array<string,mixed>
     */
    private function classifyBackupRow(
        Backup $backup,
        array $storagePaths,
        bool $storageReadable,
        ?StorageDriver $driver,
        array &$claimedDocPaths,
    ): array {
        $format = (string) ($backup->format ?? '');
        $isMultipart = $format === BackupManifestV3::FORMAT;
        $prefix = trim((string) $backup->file_path, '/');

        // The document/object whose presence proves the backup is whole.
        $expectedPath = $isMultipart
            ? $prefix.'/'.BackupManifestV3::MANIFEST_FILENAME
            : $prefix;

        $actualPath = $storageReadable ? $this->matchStoragePath($storagePaths, $expectedPath) : null;
        $present = $actualPath !== null;
        if ($actualPath !== null) {
            // Mark the metadata doc as claimed (multipart manifest, or a v2-zip sidecar).
            $claimedDocPaths[$actualPath] = true;
            $sidecar = $this->matchStoragePath($storagePaths, $expectedPath.BackupSidecarMetadata::SUFFIX);
            if ($sidecar !== null) {
                $claimedDocPaths[$sidecar] = true;
            }
        }

        $reasons = [];
        $classification = $this->classify($backup, $present, $storageReadable, $reasons);

        // Enrich from the metadata doc when we can read one (read-only).
        $descriptor = [];
        if ($present && $driver !== null) {
            $descriptor = $this->tryReadDescriptor($driver, $isMultipart
                ? $actualPath
                : ($this->matchStoragePath($storagePaths, $expectedPath.BackupSidecarMetadata::SUFFIX) ?? ''));
        }

        return [
            'backup_id' => (int) $backup->id,
            'site_id' => (int) $backup->site_id,
            'format' => $format !== '' ? $format : ($descriptor['format'] ?? null),
            'classification' => $classification,
            'path' => $actualPath ?? $expectedPath,
            'object_present' => $present,
            'composite_checksum' => $descriptor['composite_checksum'] ?? $descriptor['checksum'] ?? ($backup->checksum ?? null),
            'total_size' => (int) ($descriptor['total_size'] ?? $descriptor['file_size'] ?? $backup->file_size ?? 0),
            'file_count' => (int) ($descriptor['file_count'] ?? 0),
            'metadata' => [
                'source' => 'backups_row',
                'backup_status' => $backup->status->value,
                'verification_status' => $backup->verification_status,
                'reasons' => $reasons,
                'descriptor' => $descriptor === [] ? null : $descriptor,
            ],
        ];
    }

    /**
     * @param  list<string>  $reasons
     */
    private function classify(Backup $backup, bool $present, bool $storageReadable, array &$reasons): string
    {
        if ($backup->status !== BackupStatus::Completed) {
            $reasons[] = 'row_not_completed';

            return LegacyBackupIndexEntry::CLASS_INCOMPLETE;
        }

        if (! $storageReadable) {
            $reasons[] = 'storage_unreadable';

            return LegacyBackupIndexEntry::CLASS_UNKNOWN;
        }

        if (! $present) {
            $reasons[] = 'object_missing';

            return LegacyBackupIndexEntry::CLASS_PHANTOM;
        }

        if ((string) $backup->verification_status === 'passed') {
            $reasons[] = 'verified_restore';

            return LegacyBackupIndexEntry::CLASS_VALID;
        }

        $reasons[] = 'present_unverified';

        return LegacyBackupIndexEntry::CLASS_VERIFICATION_REQUIRED;
    }

    /**
     * @return array<string,mixed>
     */
    private function orphanEntry(string $path, string $format, int $size, ?StorageDriver $driver): array
    {
        $descriptor = $driver !== null ? $this->tryReadDescriptor($driver, $path) : [];

        return [
            'backup_id' => null,
            'site_id' => isset($descriptor['site_id']) && is_numeric($descriptor['site_id']) ? (int) $descriptor['site_id'] : null,
            'format' => $format,
            'classification' => LegacyBackupIndexEntry::CLASS_ORPHANED,
            'path' => $path,
            'object_present' => true,
            'composite_checksum' => $descriptor['composite_checksum'] ?? $descriptor['checksum'] ?? null,
            'total_size' => (int) ($descriptor['total_size'] ?? $descriptor['file_size'] ?? $size),
            'file_count' => (int) ($descriptor['file_count'] ?? 0),
            'metadata' => [
                'source' => 'storage_scan',
                'reasons' => ['object_without_row'],
                'descriptor' => $descriptor === [] ? null : $descriptor,
            ],
        ];
    }

    /**
     * Download-to-read a metadata doc and normalise it (read-only). Returns [] on
     * any failure so a bad doc never aborts the import.
     *
     * @return array<string,mixed>
     */
    private function tryReadDescriptor(StorageDriver $driver, string $path): array
    {
        if ($path === '') {
            return [];
        }
        $format = $this->reader->classifyPath($path);
        if ($format === null) {
            return [];
        }

        $tmp = (string) tempnam(sys_get_temp_dir(), 'legacyimp_');
        try {
            $driver->download($path, $tmp);
            $json = (string) file_get_contents($tmp);

            return $this->reader->read($format, $json);
        } catch (Throwable $e) {
            $this->logger->warning('legacy-import: could not read metadata doc', ['path' => $path, 'error' => $e->getMessage()]);

            return [];
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Suffix-tolerant presence match (robust to a driver base_path prefix). Returns
     * the ACTUAL storage key that matches $needle, or null.
     *
     * @param  array<string,int>  $storagePaths
     */
    private function matchStoragePath(array $storagePaths, string $needle): ?string
    {
        $needle = trim($needle, '/');
        if ($needle === '') {
            return null;
        }
        if (isset($storagePaths[$needle])) {
            return $needle;
        }
        foreach (array_keys($storagePaths) as $path) {
            if (str_ends_with(trim($path, '/'), $needle)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function persist(StorageDestination $destination, array $entry): void
    {
        LegacyBackupIndexEntry::updateOrCreate(
            [
                'storage_destination_id' => $destination->id,
                'path' => (string) $entry['path'],
            ],
            [
                'backup_id' => $entry['backup_id'],
                'site_id' => $entry['site_id'],
                'format' => $entry['format'],
                'classification' => $entry['classification'],
                'object_present' => (bool) $entry['object_present'],
                'composite_checksum' => $entry['composite_checksum'],
                'total_size' => (int) $entry['total_size'],
                'file_count' => (int) $entry['file_count'],
                'metadata' => $entry['metadata'],
                'discovered_at' => now(),
            ],
        );
    }
}
