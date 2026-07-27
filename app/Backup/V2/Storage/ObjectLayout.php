<?php

declare(strict_types=1);

namespace App\Backup\V2\Storage;

/**
 * Builds the tenant-isolated S3 object keys for a single V2 backup, derived from
 * config('backup_v2.object_prefix').
 *
 * The prefix template (default:
 * `clients/{client_id}/sites/{site_id}/backups/{backup_id}`) is expanded once and
 * every artifact key hangs off it, matching the S3 object layout in
 * docs/backup/TARGET-ARCHITECTURE.md:
 *
 *   clients/{c}/sites/{s}/backups/{b}/
 *     database/            # full logical dump chunks
 *     files/               # file payload chunks
 *     manifest.json        # inventory + hashes + chain ref
 *     checksums.json       # per-object sha256
 *     metadata.json        # type, sizes, versions, timings
 *     restore.json         # restore hints
 *     _COMPLETE            # completion marker (written last)
 *
 * Pure value object — no I/O. Reads config only through the static factory so it
 * honours the "read via config('backup_v2.*') only" contract.
 */
final class ObjectLayout
{
    private const DEFAULT_PREFIX = 'clients/{client_id}/sites/{site_id}/backups/{backup_id}';

    private readonly string $prefix;

    public function __construct(
        int|string $clientId,
        int|string $siteId,
        int|string $backupId,
        ?string $template = null,
    ) {
        $template ??= self::DEFAULT_PREFIX;

        $this->prefix = trim(strtr($template, [
            '{client_id}' => (string) $clientId,
            '{site_id}' => (string) $siteId,
            '{backup_id}' => (string) $backupId,
        ]), '/');
    }

    /**
     * Build a layout using the configured object_prefix template.
     */
    public static function forBackup(int|string $clientId, int|string $siteId, int|string $backupId): self
    {
        return new self(
            $clientId,
            $siteId,
            $backupId,
            (string) config('backup_v2.object_prefix', self::DEFAULT_PREFIX),
        );
    }

    /**
     * The expanded backup root prefix (no trailing slash).
     */
    public function prefix(): string
    {
        return $this->prefix;
    }

    /**
     * Prefix used for ListObjects/ListMultipartUploads scoping (trailing slash).
     */
    public function listPrefix(): string
    {
        return $this->prefix.'/';
    }

    /**
     * Absolute key for an arbitrary relative path under the backup root.
     */
    public function key(string $relative): string
    {
        return $this->prefix.'/'.ltrim($relative, '/');
    }

    public function database(string $name = ''): string
    {
        return $this->key('database/'.ltrim($name, '/'));
    }

    public function files(string $name = ''): string
    {
        return $this->key('files/'.ltrim($name, '/'));
    }

    public function manifest(): string
    {
        return $this->key('manifest.json');
    }

    public function checksums(): string
    {
        return $this->key('checksums.json');
    }

    public function metadata(): string
    {
        return $this->key('metadata.json');
    }

    public function restore(): string
    {
        return $this->key('restore.json');
    }

    /**
     * Completion marker — always written last, after every declared object and
     * the manifest are verified in storage.
     */
    public function completeMarker(): string
    {
        return $this->key('_COMPLETE');
    }
}
