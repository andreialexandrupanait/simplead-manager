<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BackupStatus;
use App\Models\Backup;
use App\Models\Site;
use App\Models\StorageDestination;
use App\Services\Backup\BackupManifestV3;
use App\Services\Backup\BackupSidecarMetadata;
use App\Services\Backup\Storage\StorageFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Disaster-recovery command: rebuild the `backups` table from what's actually
 * present in a storage destination, using each backup's self-describing
 * metadata (manifest.json for multipart-v3, *.meta.json sidecar for v2-zip).
 *
 * Use cases:
 *   - The Postgres DB is lost AND the daily db:dump is unrecoverable
 *   - You want to import backups produced by a forgotten/decommissioned environment
 *   - You suspect drift between DB-recorded backups and actual storage contents
 *
 * Safe by default: never overwrites an existing Backup row, only inserts new
 * ones. Dry-run mode lists what would be imported without writing anything.
 */
class ReindexBackupsFromStorageCommand extends Command
{
    protected $signature = 'backup:reindex-from-storage '
        .'{--destination= : StorageDestination ID to scan (defaults to default destination)} '
        .'{--dry-run : Print what would be imported without writing anything} '
        .'{--site-domain= : Only consider backups whose site_domain matches this (substring)}';

    protected $description = 'Reconstruct the `backups` table from metadata files found in a storage destination';

    public function handle(): int
    {
        $destination = $this->resolveDestination();
        if (! $destination) {
            $this->error('No storage destination specified or found.');

            return self::FAILURE;
        }

        $this->info("Scanning destination #{$destination->id} ({$destination->name}, type={$destination->type})...");

        $driver = StorageFactory::make($destination);

        try {
            $allFiles = $driver->listRecursive('');
        } catch (\Throwable $e) {
            $this->error("Failed to list destination: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info('Found '.count($allFiles).' files in destination.');

        // Discover backups: manifest.json paths (multipart-v3) and *.meta.json (v2-zip sidecars)
        $discovered = [];
        $sidecarsByZipPath = [];

        foreach ($allFiles as $entry) {
            $path = $entry['path'] ?? null;
            if (! $path) {
                continue;
            }

            if (str_ends_with($path, '/'.BackupManifestV3::MANIFEST_FILENAME)) {
                $discovered[] = ['kind' => 'multipart-v3', 'path' => $path];
            } elseif (str_ends_with($path, BackupSidecarMetadata::SUFFIX)) {
                $zipPath = substr($path, 0, -strlen(BackupSidecarMetadata::SUFFIX));
                $sidecarsByZipPath[$zipPath] = $path;
                $discovered[] = ['kind' => 'v2-zip', 'path' => $path, 'zip_path' => $zipPath];
            }
        }

        $this->info(sprintf('Discovered metadata for %d backup(s).', count($discovered)));

        $imported = 0;
        $skipped = 0;
        $failed = 0;
        $alreadyKnown = 0;
        $tempDir = storage_path('app/temp/reindex-'.uniqid());
        @mkdir($tempDir, 0700, true);

        foreach ($discovered as $entry) {
            try {
                $tempPath = $tempDir.'/'.uniqid().'.json';
                $driver->download($entry['path'], $tempPath);
                $content = file_get_contents($tempPath);
                @unlink($tempPath);

                if ($entry['kind'] === 'multipart-v3') {
                    $row = $this->buildRowFromManifest(BackupManifestV3::decode($content), $entry['path'], $destination);
                } else {
                    $row = $this->buildRowFromSidecar(BackupSidecarMetadata::decode($content), $entry['zip_path'], $destination);
                }

                if (! $row) {
                    $skipped++;

                    continue;
                }

                if ($this->option('site-domain') && ! str_contains($row['_site_domain_for_filter'] ?? '', (string) $this->option('site-domain'))) {
                    $skipped++;

                    continue;
                }
                unset($row['_site_domain_for_filter']);

                if (Backup::where('file_path', $row['file_path'])->exists()) {
                    $alreadyKnown++;

                    continue;
                }

                if ($this->option('dry-run')) {
                    $this->line('  [DRY RUN] would import: '.$row['file_path'].' (site_id='.($row['site_id'] ?? 'null').', type='.$row['type'].')');
                } else {
                    Backup::create($row);
                    $this->info('  imported: '.$row['file_path']);
                }
                $imported++;
            } catch (\Throwable $e) {
                $this->error("  failed for {$entry['path']}: {$e->getMessage()}");
                $failed++;
            }
        }

        @rmdir($tempDir);

        $this->newLine();
        $verb = $this->option('dry-run') ? 'would import' : 'imported';
        $this->info("Result: {$imported} {$verb}, {$alreadyKnown} already in DB, {$skipped} skipped, {$failed} failed.");

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function resolveDestination(): ?StorageDestination
    {
        if ($id = $this->option('destination')) {
            return StorageDestination::find($id);
        }

        return StorageDestination::where('is_default', true)->where('is_active', true)->first()
            ?? StorageDestination::where('is_active', true)->first();
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>|null
     */
    private function buildRowFromManifest(array $manifest, string $manifestPath, StorageDestination $destination): ?array
    {
        // Prefix is the manifest's containing directory
        $prefix = substr($manifestPath, 0, -strlen('/'.BackupManifestV3::MANIFEST_FILENAME));
        $siteDomain = $manifest['site_domain'] ?? $manifest['site_url'] ?? null;
        $siteId = $manifest['site_id'] ?? null;

        if (! $siteId && $siteDomain) {
            $siteId = $this->resolveSiteId($siteDomain, $manifest['site_url'] ?? null);
        }

        $totalSize = (int) ($manifest['total_size'] ?? 0);
        $compositeChecksum = $manifest['composite_checksum'] ?? hash('sha256', implode('', array_column($manifest['files'] ?? [], 'sha256')));

        return [
            'site_id' => $siteId,
            'storage_destination_id' => $destination->id,
            'type' => $manifest['type'] ?? 'full',
            'trigger' => $manifest['trigger'] ?? 'manual',
            'status' => BackupStatus::Completed,
            'stage' => 'completed',
            'file_path' => $prefix,
            'file_name' => BackupManifestV3::MANIFEST_FILENAME,
            'file_size' => $totalSize,
            'checksum' => $compositeChecksum,
            'format' => BackupManifestV3::FORMAT,
            'includes_files' => (bool) ($manifest['includes_files'] ?? ($manifest['type'] !== 'database')),
            'includes_database' => (bool) ($manifest['includes_database'] ?? true),
            'wp_version' => $manifest['wp_version'] ?? null,
            'php_version' => $manifest['php_version'] ?? null,
            'parent_backup_id' => $manifest['parent_backup_id'] ?? null,
            'started_at' => $this->parseDate($manifest['created_at'] ?? null),
            'completed_at' => $this->parseDate($manifest['created_at'] ?? null),
            'verified_at' => null,
            'verification_status' => 'never_tested',
            'replicas' => [[
                'destination_id' => $destination->id,
                'remote_path' => $prefix,
                'uploaded_at' => $manifest['created_at'] ?? now()->toIso8601String(),
                'status' => 'completed',
            ]],
            '_site_domain_for_filter' => (string) $siteDomain,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>|null
     */
    private function buildRowFromSidecar(array $meta, string $zipPath, StorageDestination $destination): ?array
    {
        $siteId = $meta['site_id'] ?? null;
        if (! $siteId) {
            $siteId = $this->resolveSiteId($meta['site_domain'] ?? null, $meta['site_url'] ?? null);
        }

        return [
            'site_id' => $siteId,
            'storage_destination_id' => $destination->id,
            'type' => $meta['type'] ?? 'full',
            'trigger' => $meta['trigger'] ?? 'manual',
            'status' => BackupStatus::Completed,
            'stage' => 'completed',
            'file_path' => $zipPath,
            'file_name' => $meta['file_name'] ?? basename($zipPath),
            'file_size' => (int) ($meta['file_size'] ?? 0),
            'checksum' => $meta['checksum'] ?? null,
            'format' => 'v2-zip',
            'includes_files' => (bool) ($meta['includes_files'] ?? true),
            'includes_database' => (bool) ($meta['includes_database'] ?? true),
            'wp_version' => $meta['wp_version'] ?? null,
            'php_version' => $meta['php_version'] ?? null,
            'parent_backup_id' => $meta['parent_backup_id'] ?? null,
            'manifest_path' => $meta['manifest_path'] ?? null,
            'plugins_count' => $meta['plugins_count'] ?? null,
            'themes_count' => $meta['themes_count'] ?? null,
            'db_size_mb' => $meta['db_size_mb'] ?? null,
            'started_at' => $this->parseDate($meta['created_at'] ?? null),
            'completed_at' => $this->parseDate($meta['completed_at'] ?? $meta['created_at'] ?? null),
            'verified_at' => null,
            'verification_status' => 'never_tested',
            'replicas' => [[
                'destination_id' => $destination->id,
                'remote_path' => $zipPath,
                'uploaded_at' => $meta['completed_at'] ?? now()->toIso8601String(),
                'status' => 'completed',
            ]],
            '_site_domain_for_filter' => (string) ($meta['site_domain'] ?? ''),
        ];
    }

    private function resolveSiteId(?string $siteDomain, ?string $siteUrl): ?int
    {
        if ($siteDomain) {
            $site = Site::where('url', 'like', "%{$siteDomain}%")->first();
            if ($site) {
                return $site->id;
            }
        }
        if ($siteUrl) {
            $site = Site::where('url', $siteUrl)->first();
            if ($site) {
                return $site->id;
            }
        }

        return null;
    }

    private function parseDate(?string $iso): ?Carbon
    {
        if (! $iso) {
            return null;
        }
        try {
            return Carbon::parse($iso);
        } catch (\Throwable) {
            return null;
        }
    }
}
