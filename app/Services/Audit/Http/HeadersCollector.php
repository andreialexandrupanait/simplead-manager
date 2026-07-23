<?php

declare(strict_types=1);

namespace App\Services\Audit\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Headers collector — inspects homepage response headers for HTTPS/HSTS, security
 * headers, caching/CDN hints and cookie flags. Port of
 * src/lib/collectors/headers.ts.
 */
final class HeadersCollector
{
    /** @var list<array{header: string, labelFixed?: string, labelValue?: string}> */
    private const CDN_HEADER_HINTS = [
        ['header' => 'cf-ray', 'labelFixed' => 'cloudflare (cf-ray)'],
        ['header' => 'cf-cache-status', 'labelFixed' => 'cloudflare (cf-cache-status)'],
        ['header' => 'x-vercel-id', 'labelFixed' => 'vercel (x-vercel-id)'],
        ['header' => 'x-vercel-cache', 'labelFixed' => 'vercel (x-vercel-cache)'],
        ['header' => 'x-amz-cf-id', 'labelFixed' => 'cloudfront (x-amz-cf-id)'],
        ['header' => 'x-amz-cf-pop', 'labelFixed' => 'cloudfront (x-amz-cf-pop)'],
        ['header' => 'x-fastly-request-id', 'labelFixed' => 'fastly (x-fastly-request-id)'],
        ['header' => 'x-served-by', 'labelValue' => 'x-served-by'],
        ['header' => 'x-cache', 'labelValue' => 'x-cache'],
        ['header' => 'x-cdn', 'labelValue' => 'x-cdn'],
        ['header' => 'x-akamai-transformed', 'labelFixed' => 'akamai (x-akamai-transformed)'],
        ['header' => 'via', 'labelValue' => 'via'],
    ];

    private const CDN_SERVER_NAMES = '/cloudflare|cloudfront|fastly|akamai|vercel|netlify|varnish|bunny/i';

    /** Case-insensitive header line, or null when the header is absent. */
    private static function headerLine(Response $res, string $name): ?string
    {
        $lower = strtolower($name);
        foreach ($res->headers() as $k => $values) {
            if (strtolower($k) === $lower) {
                return implode(', ', $values);
            }
        }

        return null;
    }

    /**
     * @return array{present: bool, value: string|null}
     */
    private static function presence(Response $res, string $name): array
    {
        $value = self::headerLine($res, $name);

        return ['present' => $value !== null, 'value' => $value];
    }

    /**
     * @return list<string>
     */
    private static function detectCdnHints(Response $res): array
    {
        $hints = [];
        foreach (self::CDN_HEADER_HINTS as $hint) {
            $value = self::headerLine($res, $hint['header']);
            if ($value === null) {
                continue;
            }
            $hints[] = isset($hint['labelFixed']) ? $hint['labelFixed'] : "{$hint['labelValue']}: {$value}";
        }
        $server = self::headerLine($res, 'server');
        if ($server !== null && preg_match(self::CDN_SERVER_NAMES, $server) === 1) {
            $hints[] = "server: {$server}";
        }

        return $hints;
    }

    /**
     * @return list<string>
     */
    private static function getSetCookies(Response $res): array
    {
        $headers = $res->headers();
        foreach ($headers as $name => $values) {
            if (strtolower($name) === 'set-cookie') {
                return array_values($values);
            }
        }

        return [];
    }

    private static function parseHstsMaxAge(?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return preg_match('/max-age\s*=\s*"?(\d+)"?/i', $value, $m) === 1 ? (int) $m[1] : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function collect(string $url): array
    {
        $normalized = UrlHelper::normalizeUrl($url);
        $isHttps = str_starts_with(strtolower($normalized), 'https:');

        $res = null;
        $method = null;

        // Try HEAD first; some servers reject it, fall back to GET.
        try {
            $headRes = Http::head($normalized);
            if ($headRes->status() < 400) {
                $res = $headRes;
                $method = 'HEAD';
            }
        } catch (ConnectionException) {
            // fall through to GET
        }
        if ($res === null) {
            try {
                $res = Http::get($normalized);
                $method = 'GET';
            } catch (ConnectionException) {
                $res = null;
                $method = null;
            }
        }

        if ($res === null) {
            return [
                'status' => null,
                'method' => null,
                'https' => ['valid' => false, 'hsts' => false, 'hstsMaxAge' => null],
                'security' => [
                    'csp' => ['present' => false, 'value' => null],
                    'xContentTypeOptions' => ['present' => false, 'value' => null],
                    'xFrameOptions' => ['present' => false, 'value' => null],
                    'referrerPolicy' => ['present' => false, 'value' => null],
                    'permissionsPolicy' => ['present' => false, 'value' => null],
                ],
                'caching' => ['cacheControl' => null, 'cdnHints' => []],
                'cookies' => ['count' => 0, 'flags' => ['secure' => 0, 'httpOnly' => 0, 'sameSite' => 0]],
            ];
        }

        $hstsValue = self::headerLine($res, 'strict-transport-security');
        $setCookies = self::getSetCookies($res);

        return [
            'status' => $res->status(),
            'method' => $method,
            'https' => [
                'valid' => $isHttps && $res->status() < 500,
                'hsts' => $hstsValue !== null,
                'hstsMaxAge' => self::parseHstsMaxAge($hstsValue),
            ],
            'security' => [
                'csp' => self::presence($res, 'content-security-policy'),
                'xContentTypeOptions' => self::presence($res, 'x-content-type-options'),
                'xFrameOptions' => self::presence($res, 'x-frame-options'),
                'referrerPolicy' => self::presence($res, 'referrer-policy'),
                'permissionsPolicy' => self::presence($res, 'permissions-policy'),
            ],
            'caching' => [
                'cacheControl' => self::headerLine($res, 'cache-control'),
                'cdnHints' => self::detectCdnHints($res),
            ],
            'cookies' => [
                'count' => count($setCookies),
                'flags' => [
                    'secure' => count(array_filter($setCookies, static fn (string $c): bool => preg_match('/;\s*secure(;|$)/i', $c) === 1)),
                    'httpOnly' => count(array_filter($setCookies, static fn (string $c): bool => preg_match('/;\s*httponly(;|$)/i', $c) === 1)),
                    'sameSite' => count(array_filter($setCookies, static fn (string $c): bool => preg_match('/;\s*samesite\s*=/i', $c) === 1)),
                ],
            ],
        ];
    }
}
