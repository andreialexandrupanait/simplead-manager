<?php

declare(strict_types=1);

namespace App\Services\Backup;

/**
 * Layout + manifest format for streaming "multipart-v3" backups.
 *
 * Each backup lives under its own remote prefix instead of being a single
 * combined ZIP file. Layout:
 *
 *   {site_domain}/{type}-{timestamp}/
 *     manifest.json          <- list of files + sha256 + sizes (this struct)
 *     database.sql.gz        <- gzipped DB dump
 *     chunks/0.zip           <- files chunk 0
 *     chunks/1.zip           <- files chunk 1
 *     ...
 *     deleted-files.json     <- (incremental only) deleted paths from manifest diff
 *
 * Trade-offs vs the older "v2-zip" format:
 *   + Local disk peak = max(single chunk size) instead of full backup size
 *   + Per-file failure isolation: a corrupt chunk only invalidates one chunk
 *   + Resumable: re-uploading is per-file, retry of a failed chunk is cheap
 *   - Restore must download N small files instead of one (more API calls)
 *   - Manifest is a separate fetch
 *
 * The format version is recorded both as `Backup.format = 'multipart-v3'` and as
 * `manifest.json::format_version = 3`. Verify both for safety.
 */
final class BackupManifestV3
{
    public const FORMAT = 'multipart-v3';

    public const FORMAT_VERSION = 3;

    public const MANIFEST_FILENAME = 'manifest.json';

    /**
     * Build the remote prefix where this backup's files live.
     */
    public static function prefixFor(string $siteDomain, string $type, \DateTimeInterface $timestamp): string
    {
        return $siteDomain.'/'.$type.'-'.$timestamp->format('Y-m-d-His');
    }

    /**
     * Construct the manifest body. Files entries are appended as upload completes.
     *
     * @param  list<array{name: string, size: int, sha256: string}>  $files
     * @return array<string, mixed>
     */
    public static function build(
        string $siteUrl,
        string $siteName,
        string $type,
        string $trigger,
        ?string $wpVersion,
        ?string $phpVersion,
        ?int $parentBackupId,
        array $files,
    ): array {
        $totalSize = array_sum(array_column($files, 'size'));

        return [
            'format_version' => self::FORMAT_VERSION,
            'site_url' => $siteUrl,
            'site_name' => $siteName,
            'type' => $type,
            'trigger' => $trigger,
            'created_at' => now()->toIso8601String(),
            'wp_version' => $wpVersion,
            'php_version' => $phpVersion,
            'parent_backup_id' => $parentBackupId,
            'files' => $files,
            'total_size' => $totalSize,
        ];
    }

    public static function encode(array $manifest): string
    {
        return json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws \RuntimeException on malformed JSON or missing required fields
     */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new \RuntimeException('manifest.json is not a valid JSON object');
        }

        foreach (['format_version', 'type', 'files'] as $required) {
            if (! array_key_exists($required, $data)) {
                throw new \RuntimeException("manifest.json missing required field: {$required}");
            }
        }

        if ((int) $data['format_version'] !== self::FORMAT_VERSION) {
            throw new \RuntimeException("Unsupported manifest format_version: {$data['format_version']} (expected ".self::FORMAT_VERSION.')');
        }

        if (! is_array($data['files'])) {
            throw new \RuntimeException('manifest.json::files is not an array');
        }

        return $data;
    }
}
