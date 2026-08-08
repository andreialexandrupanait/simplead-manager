<?php

declare(strict_types=1);

namespace App\Backup\V2\Storage;

/**
 * Builds the S3 object keys for a single backup, derived from
 * config('backup_v2.object_prefix').
 *
 * The prefix template (default:
 * `mentenanta/{site_domain}/{date}/{time}-{type}-{trigger}`) is expanded once and
 * every artifact key hangs off it:
 *
 *   mentenanta/feaagalati.ro/2026-08-08/03-12-05-full-scheduled/
 *     database/            # full logical dump chunks
 *     files/               # file payload chunks
 *     manifest.json        # inventory + hashes + chain ref
 *     checksums.json       # per-object sha256
 *     metadata.json        # type, sizes, versions, timings
 *     restore.json         # restore hints
 *     _COMPLETE            # completion marker (written last)
 *
 * The layout used to be `clients/{client_id}/sites/{site_id}/backups/{backup_id}`,
 * which is addressable but unreadable: finding one site's backups meant knowing
 * two numeric ids, and a bucket listing showed `clients/3/sites/17/backups/…`
 * rather than a domain and a date. Numbers are how the database refers to a site;
 * they are not how a person looks for one.
 *
 * The day is a folder and each run is a leaf inside it, so a site that backs up
 * more than once in a day — a manual run on a night that already ran its
 * scheduled one — reads as two named entries under one date rather than as two
 * things that need telling apart. The leaf says what it was: the time it started,
 * what it covered, and who asked for it.
 *
 * Uniqueness is not decoration here. Two sessions resolving to the same prefix
 * means an overwritten manifest, interleaved chunks, and retention deleting
 * objects belonging to the other backup. The time carries seconds for exactly
 * that reason: minutes alone would collide for a site backed up twice inside one
 * minute, and the per-site lock makes the same second unreachable.
 *
 * Changing this template is safe for existing backups: {@see SessionLayoutResolver}
 * freezes the expanded prefix on the session at first use and reads it verbatim
 * thereafter, so a template change moves nothing and orphans nothing — it only
 * decides where the NEXT backup is written.
 *
 * Pure value object — no I/O. Reads config only through the static factory so it
 * honours the "read via config('backup_v2.*') only" contract.
 */
final class ObjectLayout
{
    private const DEFAULT_PREFIX = 'mentenanta/{site_domain}/{date}/{time}-{type}-{trigger}';

    private readonly string $prefix;

    public function __construct(
        int|string $clientId,
        int|string $siteId,
        int|string $backupId,
        ?string $template = null,
        string $siteDomain = '',
        string $date = '',
        string $time = '',
        string $type = '',
        string $trigger = '',
    ) {
        $template ??= self::DEFAULT_PREFIX;

        $this->prefix = trim(strtr($template, [
            '{client_id}' => (string) $clientId,
            '{site_id}' => (string) $siteId,
            '{backup_id}' => (string) $backupId,
            '{site_domain}' => self::slugDomain($siteDomain, $siteId),
            '{date}' => $date !== '' ? $date : now()->format('Y-m-d'),
            '{time}' => $time !== '' ? $time : now()->format('H-i-s'),
            '{type}' => self::slugSegment($type, 'backup'),
            '{trigger}' => self::slugSegment($trigger, 'run'),
        ]), '/');
    }

    /**
     * Build a layout using the configured object_prefix template.
     */
    public static function forBackup(
        int|string $clientId,
        int|string $siteId,
        int|string $backupId,
        string $siteDomain = '',
        string $date = '',
        string $time = '',
        string $type = '',
        string $trigger = '',
    ): self {
        return new self(
            $clientId,
            $siteId,
            $backupId,
            (string) config('backup_v2.object_prefix', self::DEFAULT_PREFIX),
            $siteDomain,
            $date,
            $time,
            $type,
            $trigger,
        );
    }

    /**
     * One key segment, reduced to what is safe and readable in an object key.
     *
     * Falls back to a caller-supplied word rather than to an empty string: an
     * empty segment would collapse `…/03-12-05--scheduled` and, worse, could make
     * two different backups resolve to the same prefix.
     */
    private static function slugSegment(string $value, string $fallback): string
    {
        $slug = strtolower(trim($value));
        $slug = (string) preg_replace('/[^a-z0-9]+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : $fallback;
    }

    /**
     * A site's URL reduced to something safe to put in an object key.
     *
     * Takes the host out of `https://feaagalati.ro/` and drops a leading `www.`,
     * so the same site cannot end up under two different folders depending on how
     * its URL happens to be written. Anything left outside [a-z0-9.-] becomes a
     * dash rather than being dropped, so two distinct hosts cannot collapse onto
     * one prefix.
     *
     * Falls back to `site-{id}` rather than to an empty segment: a site with an
     * unparseable URL must still get its own root, not share `mentenanta//…` with
     * every other broken one.
     */
    private static function slugDomain(string $url, int|string $siteId): string
    {
        $host = strtolower(trim($url));

        if ($host !== '' && str_contains($host, '//')) {
            $host = (string) (parse_url($host, PHP_URL_HOST) ?: '');
        }

        $host = preg_replace('/^www\./', '', trim($host, '/'));
        $host = (string) preg_replace('/[^a-z0-9.-]+/', '-', (string) $host);
        $host = trim($host, '-.');

        return $host !== '' ? $host : 'site-'.$siteId;
    }

    /**
     * Adopt a prefix that was already written to, verbatim.
     *
     * A backup's objects live wherever they were first put. Recomputing the
     * prefix later — from ids that can change, or from a template that can be
     * edited — does not move them; it only stops anything finding them. This is
     * the only way to address an existing backup, and {@see SessionLayoutResolver}
     * is the only thing that should call it.
     */
    public static function fromPrefix(string $prefix): self
    {
        // Passed as the template rather than assigned: the prefix carries no
        // placeholders, so strtr leaves it untouched and the property stays
        // readonly — a stored prefix must not be mutable after the fact.
        return new self('', '', '', $prefix);
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
