<?php

declare(strict_types=1);

namespace App\Backup\V2\Storage;

use RuntimeException;

/**
 * Where the engine puts bytes that are too big to hold in memory.
 *
 * Not sys_get_temp_dir(). In production /tmp is a tmpfs — RAM with a filesystem
 * in front of it — sized 512 MB in the queue container and **64 MB** in the web
 * one. Every part of this engine streams objects the size of a client's site
 * through temporary files: a 25 MB chunk downloaded, decrypted to a second copy,
 * then buffered again as an HTTP request body is already past 64 MB, and the
 * `fast` profile's chunks are four times that.
 *
 * The symptom is not subtle and not rare — the first real restore attempted in
 * production died with `fwrite(): No space left on device` after three seconds —
 * but it had never been seen, because no restore had ever been run outside a lab
 * where /tmp is an ordinary disk.
 *
 * The storage volume is the only writable real disk the container has, and it has
 * hundreds of gigabytes behind it. Everything large goes here.
 */
final class WorkDir
{
    /**
     * The working directory, created if it does not exist yet.
     */
    public static function path(): string
    {
        $dir = (string) config('backup_v2.work_dir', sys_get_temp_dir());

        if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Could not create the backup work directory {$dir}.");
        }

        return $dir;
    }

    /**
     * A unique temporary file inside the working directory.
     *
     * Drop-in for tempnam(sys_get_temp_dir(), $prefix) — same contract, somewhere
     * the bytes actually fit.
     */
    public static function temp(string $prefix): string
    {
        return (string) tempnam(self::path(), $prefix);
    }
}
