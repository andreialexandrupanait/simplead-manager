<?php

declare(strict_types=1);

namespace App\Services\Audit;

/**
 * The public report slug — derived from the client's domain, unique, non-reserved.
 * Pure (no DB): the caller supplies the taken-check. Port of report/slug.ts.
 */
final class AuditReportSlug
{
    /** Slugs that must never be assigned to a report (route collisions). */
    public const RESERVED = ['api', 'r', 'admin', 'login', 'logout', 'audits', 'dashboard', 'settings'];

    /**
     * Normalize a domain into a base slug: no protocol/path, lowercase,
     * non-alphanumeric runs become hyphens, no leading/trailing hyphens.
     * E.g. "https://Select-Soft.ro/produse" → "select-soft-ro".
     */
    public static function baseFromDomain(string $domain): string
    {
        $s = preg_replace('~^https?://~i', '', $domain) ?? $domain;
        $s = preg_replace('~/.*$~', '', $s) ?? $s;
        $s = strtolower($s);
        $s = preg_replace('~[^a-z0-9]+~', '-', $s) ?? $s;

        return trim($s, '-');
    }

    /** A slug is usable if it matches the minimal form and is not reserved. */
    public static function isUsable(string $slug): bool
    {
        return preg_match('~^[a-z0-9]([a-z0-9-]{1,62})[a-z0-9]$~', $slug) === 1
            && ! in_array($slug, self::RESERVED, true);
    }

    /**
     * Pick an available slug from a base: if the base is reserved/taken or too
     * short, append a numeric suffix (-2, -3, …). Reserved slugs are never used.
     *
     * @param  callable(string): bool  $isTaken  true if the slug is already used by another report
     */
    public static function resolve(string $base, callable $isTaken): string
    {
        $candidate = $base;
        if (preg_match('~^[a-z0-9]([a-z0-9-]{1,62})[a-z0-9]$~', $candidate) !== 1) {
            $candidate = ltrim(($base !== '' ? $base : 'raport').'-raport', '-');
        }

        for ($n = 1; $n < 10000; $n++) {
            $slug = $n <= 1 ? $candidate : "{$candidate}-{$n}";
            if (! self::isUsable($slug)) {
                continue;
            }
            if (! $isTaken($slug)) {
                return $slug;
            }
        }

        return $candidate.'-'.base_convert((string) strlen($candidate.$base), 10, 36);
    }
}
