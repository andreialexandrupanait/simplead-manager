<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Which engine produced a backup, or should produce a site's next one.
 *
 * Temporary by design. It exists so the V1 code paths that reach for a `backups`
 * row — retention's delete branch, stuck recovery, restore-verification, restore
 * and download — can tell apart a row whose objects are a single archive at
 * `file_path` from one whose objects live under an S3 prefix. Without that
 * distinction retention calls DeleteObject on a prefix, S3 answers 204 for the
 * key that is not there, and the row disappears leaving every object orphaned.
 *
 * Once every site is on the new engine and the old one is deleted there is no
 * second engine to tell apart, and this goes with it.
 */
enum BackupEngine: string
{
    /** Archive built on the client host, stored as one object at `file_path`. */
    case V1 = 'v1';

    /** Chunked, resumable, consistent; objects under an immutable S3 prefix. */
    case V2 = 'v2';

    public function label(): string
    {
        return match ($this) {
            self::V1 => 'Legacy engine',
            self::V2 => 'Backup engine',
        };
    }
}
