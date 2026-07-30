<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Faza 6 — tiny, dependency-free semantic-version helper for the update
 * decision engine. Deliberately lenient: WordPress plugin versions are not
 * strictly semver (things like "5", "2.1.0-beta2", "v1.4" show up), so every
 * method degrades gracefully rather than throwing on odd input.
 */
final class Semver
{
    /**
     * Is going from $from to $to a MAJOR version bump?
     *
     * The major component is the first numeric segment of each version, read
     * as `(int) explode('.', ltrim($v, 'v'))[0]`. A cast is used so non-numeric
     * suffixes ("1beta") still parse, and it is a major bump only when that
     * segment *increases* — a downgrade or same-major change is not.
     */
    public static function isMajorBump(string $from, string $to): bool
    {
        return self::major($to) > self::major($from);
    }

    /**
     * Is $to strictly newer than $from? Uses PHP's version_compare, which is
     * tolerant of the "-beta"/"RC" suffixes plugins publish.
     */
    public static function isNewer(string $from, string $to): bool
    {
        return version_compare(self::normalize($from), self::normalize($to), '<');
    }

    /**
     * Does $version satisfy a minimum of $minimum (i.e. version >= minimum)?
     * Used to decide whether an available update actually *reaches* the version
     * a vulnerability was fixed in.
     */
    public static function atLeast(string $version, string $minimum): bool
    {
        if (trim($version) === '' || trim($minimum) === '') {
            return false;
        }

        return version_compare(self::normalize($version), self::normalize($minimum), '>=');
    }

    /**
     * First numeric segment of a version, robust to a leading "v" and to
     * non-numeric suffixes on that segment.
     */
    public static function major(string $v): int
    {
        $normalized = self::normalize($v);
        $segments = explode('.', $normalized);

        return (int) ($segments[0] ?? 0);
    }

    private static function normalize(string $v): string
    {
        return ltrim(trim($v), 'vV');
    }
}
