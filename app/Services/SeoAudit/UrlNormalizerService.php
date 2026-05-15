<?php

declare(strict_types=1);

namespace App\Services\SeoAudit;

class UrlNormalizerService
{
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

        return $n;
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
