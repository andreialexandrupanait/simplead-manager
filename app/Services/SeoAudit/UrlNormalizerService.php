<?php

declare(strict_types=1);

namespace App\Services\SeoAudit;

class UrlNormalizerService
{
    /**
     * Query params that never distinguish a page (tracking/analytics noise) and
     * so are stripped before hashing — everything else is significant and kept.
     */
    private const TRACKING_PARAMS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        'gclid', 'fbclid', 'mc_cid', 'mc_eid', 'msclkid', '_ga',
    ];

    public static function normalize(string $url): string
    {
        $p = parse_url($url);
        if ($p === false || ! isset($p['host'])) {
            return $url;
        } $s = strtolower($p['scheme'] ?? 'https');
        $h = strtolower($p['host']);
        $path = $p['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        } else {
            $path = rtrim($path, '/');
        } $n = $s.'://'.$h.$path;

        // P3-20: preserve SIGNIFICANT query strings so that e.g. ?p=1 and ?p=2
        // (paginated / parameter-driven pages) are treated as distinct URLs
        // instead of collapsing to the same hash. Tracking params are dropped
        // and the remainder is sorted so ordering differences still dedupe.
        $query = self::normalizeQuery($p['query'] ?? '');
        if ($query !== '') {
            $n .= '?'.$query;
        }

        return $n;
    }

    private static function normalizeQuery(string $query): string
    {
        if ($query === '') {
            return '';
        }

        parse_str($query, $params);
        foreach (self::TRACKING_PARAMS as $tracking) {
            unset($params[$tracking]);
        }
        if ($params === []) {
            return '';
        }
        ksort($params);

        return http_build_query($params);
    }

    public static function hash(string $url): string
    {
        return hash('sha256', self::normalize($url));
    }

    public static function areEqual(string $a, string $b): bool
    {
        return self::normalize($a) === self::normalize($b);
    }

    public static function extractHost(string $url): ?string
    {
        $h = parse_url($url, PHP_URL_HOST);

        return $h !== false ? strtolower((string) $h) : null;
    }

    public static function isSameDomain(string $url, string $base): bool
    {
        $h = self::extractHost($url);
        if (! $h) {
            return false;
        } $base = strtolower($base);

        return $h === $base || $h === 'www.'.$base || 'www.'.$h === $base;
    }
}
