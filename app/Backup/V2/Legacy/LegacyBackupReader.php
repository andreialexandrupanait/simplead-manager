<?php

declare(strict_types=1);

namespace App\Backup\V2\Legacy;

use App\Services\Backup\BackupManifestV3;
use App\Services\Backup\BackupSidecarMetadata;

/**
 * Read-only reader for LEGACY backup formats so the V2 engine can index/list
 * pre-existing artifacts without owning their write path:
 *
 *   - "multipart-v3": a per-backup prefix with a `manifest.json`
 *     (App\Services\Backup\BackupManifestV3).
 *   - "v2-zip": a single .zip archive with a sidecar `<file>.meta.json`
 *     (App\Services\Backup\BackupSidecarMetadata).
 *
 * STRICTLY read-only: it parses/normalises metadata; it never moves, rewrites,
 * or deletes objects. Actually restoring a legacy artifact stays gated behind
 * config('backup_v2.legacy_restore_enabled').
 *
 * Reuses the existing decode()/validation in the V1 helpers so the contract for
 * a valid manifest/sidecar is single-sourced.
 */
final class LegacyBackupReader
{
    public const FORMAT_MULTIPART_V3 = 'multipart-v3';

    public const FORMAT_V2_ZIP = 'v2-zip';

    /**
     * Parse a multipart-v3 manifest.json body into a normalised descriptor.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException on malformed/invalid manifest
     */
    public function readManifest(string $json): array
    {
        $manifest = BackupManifestV3::decode($json);

        $files = is_array($manifest['files'] ?? null) ? $manifest['files'] : [];
        $totalSize = (int) ($manifest['total_size'] ?? array_sum(array_column($files, 'size')));

        return [
            'format' => self::FORMAT_MULTIPART_V3,
            'format_version' => (int) $manifest['format_version'],
            'site_id' => $manifest['site_id'] ?? null,
            'site_url' => $manifest['site_url'] ?? null,
            'site_domain' => $manifest['site_domain'] ?? null,
            'type' => $manifest['type'],
            'trigger' => $manifest['trigger'] ?? null,
            'created_at' => $manifest['created_at'] ?? null,
            'wp_version' => $manifest['wp_version'] ?? null,
            'php_version' => $manifest['php_version'] ?? null,
            'parent_backup_id' => $manifest['parent_backup_id'] ?? null,
            'includes_files' => (bool) ($manifest['includes_files'] ?? ($manifest['type'] !== 'database')),
            'includes_database' => (bool) ($manifest['includes_database'] ?? true),
            'composite_checksum' => $manifest['composite_checksum']
                ?? hash('sha256', implode('', array_column($files, 'sha256'))),
            'total_size' => $totalSize,
            'file_count' => count($files),
            'files' => $files,
        ];
    }

    /**
     * Parse a v2-zip sidecar (<file>.meta.json) body into a normalised descriptor.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException on malformed/invalid sidecar
     */
    public function readSidecar(string $json): array
    {
        $meta = BackupSidecarMetadata::decode($json);

        return [
            'format' => self::FORMAT_V2_ZIP,
            'schema_version' => (int) $meta['schema_version'],
            'site_id' => $meta['site_id'] ?? null,
            'site_url' => $meta['site_url'] ?? null,
            'site_domain' => $meta['site_domain'] ?? null,
            'type' => $meta['type'],
            'trigger' => $meta['trigger'] ?? null,
            'created_at' => $meta['created_at'] ?? null,
            'completed_at' => $meta['completed_at'] ?? null,
            'file_name' => $meta['file_name'] ?? null,
            'file_size' => (int) ($meta['file_size'] ?? 0),
            'checksum' => $meta['checksum'] ?? null,
            'wp_version' => $meta['wp_version'] ?? null,
            'php_version' => $meta['php_version'] ?? null,
            'parent_backup_id' => $meta['parent_backup_id'] ?? null,
            'includes_files' => (bool) ($meta['includes_files'] ?? true),
            'includes_database' => (bool) ($meta['includes_database'] ?? true),
        ];
    }

    /**
     * Classify a storage object path as a known legacy metadata document, or null.
     *
     * @return self::FORMAT_*|null
     */
    public function classifyPath(string $path): ?string
    {
        if (str_ends_with($path, '/'.BackupManifestV3::MANIFEST_FILENAME)) {
            return self::FORMAT_MULTIPART_V3;
        }

        if (str_ends_with($path, BackupSidecarMetadata::SUFFIX)) {
            return self::FORMAT_V2_ZIP;
        }

        return null;
    }

    /**
     * Dispatch parse based on the metadata document body's format.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public function read(string $format, string $json): array
    {
        return match ($format) {
            self::FORMAT_MULTIPART_V3 => $this->readManifest($json),
            self::FORMAT_V2_ZIP => $this->readSidecar($json),
            default => throw new \RuntimeException("Unknown legacy backup format: {$format}"),
        };
    }
}
