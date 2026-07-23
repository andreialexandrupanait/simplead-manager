<?php

declare(strict_types=1);

namespace App\Services\Audit\Http;

/**
 * URL helpers for the v2 fetch checks. Port of the URL utilities in
 * src/lib/collectors/http.ts (normalizeUrl / originOf / resolvePath) and
 * bareDomain from redirects.ts.
 */
final class UrlHelper
{
    public static function normalizeUrl(string $input, string $defaultScheme = 'https'): string
    {
        $s = trim($input);
        if ($s === '') {
            throw new \InvalidArgumentException('normalizeUrl: empty input');
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $s) !== 1) {
            $s = "{$defaultScheme}://{$s}";
        }
        $p = parse_url($s);
        if ($p === false || ! isset($p['host'])) {
            throw new \InvalidArgumentException("normalizeUrl: invalid input ({$input})");
        }
        $scheme = strtolower($p['scheme'] ?? $defaultScheme);
        $port = isset($p['port']) ? ':'.$p['port'] : '';
        $path = ($p['path'] ?? '') !== '' ? $p['path'] : '/';
        $query = isset($p['query']) ? '?'.$p['query'] : '';

        return "{$scheme}://{$p['host']}{$port}{$path}{$query}";
    }

    public static function originOf(string $input): string
    {
        $p = parse_url(self::normalizeUrl($input));
        $port = isset($p['port']) ? ':'.$p['port'] : '';

        return strtolower((string) ($p['scheme'] ?? 'https')).'://'.($p['host'] ?? '').$port;
    }

    public static function resolvePath(string $base, string $path): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) === 1) {
            return $path;
        }
        if (str_starts_with($path, '/')) {
            return self::originOf($base).$path;
        }

        return rtrim(self::normalizeUrl($base), '/').'/'.ltrim($path, '/');
    }

    /** Strip scheme, path and www down to a bare domain. Port of bareDomain(). */
    public static function bareDomain(string $input): string
    {
        $s = preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', trim($input)) ?? '';
        $s = explode('#', explode('?', explode('/', $s)[0])[0])[0];

        return strtolower(preg_replace('/^www\./i', '', $s) ?? $s);
    }
}
