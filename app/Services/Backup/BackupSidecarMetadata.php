<?php

declare(strict_types=1);

namespace App\Services\Backup;

use App\Models\Backup;
use App\Models\Site;
use App\Services\Backup\Storage\StorageDriver;

/**
 * Self-describing sidecar metadata written next to v2-zip backup archives.
 *
 * Multipart-v3 backups already have a manifest.json acting as their catalog,
 * so this helper is only used for the legacy v2-zip path. Together they make
 * every backup in storage discoverable without the Laravel DB — the reindex
 * command can scan destinations and rebuild the `backups` table from
 * manifest.json (multipart-v3) or {file}.meta.json (v2-zip) alone.
 */
final class BackupSidecarMetadata
{
    public const SCHEMA_VERSION = 1;

    public const SUFFIX = '.meta.json';

    /**
     * Build the metadata document for a v2-zip backup that just finished.
     *
     * @return array<string, mixed>
     */
    public static function buildForV2Zip(Backup $backup, Site $site): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'format' => 'v2-zip',
            'site_id' => $site->id,
            'site_url' => $site->url,
            'site_domain' => $site->domain,
            'site_name' => $site->name,
            'type' => $backup->type,
            'trigger' => $backup->trigger,
            'created_at' => $backup->created_at?->toIso8601String(),
            'completed_at' => $backup->completed_at?->toIso8601String(),
            'file_name' => $backup->file_name,
            'file_size' => $backup->file_size,
            'checksum' => $backup->checksum,
            'includes_files' => $backup->includes_files,
            'includes_database' => $backup->includes_database,
            'wp_version' => $backup->wp_version,
            'php_version' => $backup->php_version,
            'parent_backup_id' => $backup->parent_backup_id,
            'manifest_path' => $backup->manifest_path,
            'plugins_count' => $backup->plugins_count,
            'themes_count' => $backup->themes_count,
            'db_size_mb' => $backup->db_size_mb,
        ];
    }

    /**
     * Upload the sidecar next to a v2-zip backup. Best-effort: failure here
     * doesn't fail the backup (the .zip is still safe — we just lose
     * self-describability for that particular file).
     */
    public static function uploadAlongside(StorageDriver $driver, string $remoteZipPath, array $metadata): bool
    {
        $sidecarPath = self::sidecarPathFor($remoteZipPath);
        $tempPath = sys_get_temp_dir().'/sidecar-'.uniqid().'.json';
        file_put_contents($tempPath, json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        try {
            $driver->upload($tempPath, $sidecarPath);

            return true;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning("BackupSidecarMetadata: failed to upload sidecar for {$remoteZipPath}: {$e->getMessage()}");

            return false;
        } finally {
            @unlink($tempPath);
        }
    }

    public static function sidecarPathFor(string $remoteZipPath): string
    {
        return $remoteZipPath.self::SUFFIX;
    }

    /**
     * Decode + minimally validate.
     *
     * @return array<string, mixed>
     *
     * @throws \RuntimeException
     */
    public static function decode(string $json): array
    {
        $data = json_decode($json, true);
        if (! is_array($data)) {
            throw new \RuntimeException('sidecar metadata is not valid JSON');
        }
        if (($data['schema_version'] ?? null) !== self::SCHEMA_VERSION) {
            throw new \RuntimeException('unsupported sidecar schema_version: '.($data['schema_version'] ?? 'null'));
        }
        foreach (['site_domain', 'type', 'created_at', 'file_name'] as $required) {
            if (empty($data[$required])) {
                throw new \RuntimeException("sidecar missing required field: {$required}");
            }
        }

        return $data;
    }
}
