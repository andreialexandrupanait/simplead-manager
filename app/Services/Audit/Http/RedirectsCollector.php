<?php

declare(strict_types=1);

namespace App\Services\Audit\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Redirects collector — tests the four host variants (http/https × www/non-www)
 * with manual redirect following to determine the canonical host and whether
 * every variant reaches it in a single hop. Port of src/lib/collectors/redirects.ts.
 */
final class RedirectsCollector
{
    public const MAX_HOPS = 5;

    private static function isRedirect(int $status): bool
    {
        return $status >= 300 && $status < 400;
    }

    /** Resolve a Location header (absolute or relative) against the current URL. */
    private static function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $location) === 1) {
            return $location;
        }
        $b = parse_url($base);
        $origin = strtolower((string) ($b['scheme'] ?? 'https')).'://'.($b['host'] ?? '')
            .(isset($b['port']) ? ':'.$b['port'] : '');
        if (str_starts_with($location, '/')) {
            return $origin.$location;
        }
        $basePath = $b['path'] ?? '/';
        $slash = strrpos($basePath, '/');
        $dir = $slash !== false ? substr($basePath, 0, $slash + 1) : '/';

        return $origin.$dir.$location;
    }

    /**
     * @return array{url: string, chain: list<array{url: string, status: int}>, finalUrl: string, finalStatus: int|null, error?: string}
     */
    private function followChain(string $startUrl): array
    {
        $chain = [];
        $current = $startUrl;
        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            try {
                $res = Http::withoutRedirecting()->get($current);
            } catch (ConnectionException $e) {
                return ['url' => $startUrl, 'chain' => $chain, 'finalUrl' => $current, 'finalStatus' => null, 'error' => $e->getMessage()];
            }
            $status = $res->status();
            $chain[] = ['url' => $current, 'status' => $status];
            if (! self::isRedirect($status)) {
                return ['url' => $startUrl, 'chain' => $chain, 'finalUrl' => $current, 'finalStatus' => $status];
            }
            $location = $res->header('Location');
            if ($location === '') {
                return ['url' => $startUrl, 'chain' => $chain, 'finalUrl' => $current, 'finalStatus' => $status, 'error' => 'Redirect without Location header'];
            }
            $next = self::resolveLocation($current, $location);
            foreach ($chain as $h) {
                if ($h['url'] === $next) {
                    return ['url' => $startUrl, 'chain' => $chain, 'finalUrl' => $next, 'finalStatus' => $status, 'error' => 'Redirect loop detected'];
                }
            }
            $current = $next;
        }

        return [
            'url' => $startUrl,
            'chain' => $chain,
            'finalUrl' => $current,
            'finalStatus' => $chain[count($chain) - 1]['status'] ?? null,
            'error' => 'More than '.self::MAX_HOPS.' redirects',
        ];
    }

    /**
     * @return array{variants: list<array<string, mixed>>, canonicalHost: string|null, singleHop: bool, issues: list<string>}
     */
    public function collect(string $domain): array
    {
        $bare = UrlHelper::bareDomain($domain);
        $variantUrls = [
            "http://{$bare}/",
            "http://www.{$bare}/",
            "https://{$bare}/",
            "https://www.{$bare}/",
        ];

        $variants = [];
        foreach ($variantUrls as $url) {
            $variants[] = $this->followChain($url);
        }

        // Canonical host = most common https destination host among successful variants.
        $hostCounts = [];
        foreach ($variants as $v) {
            if (isset($v['error']) && $v['finalStatus'] === null) {
                continue;
            }
            $u = parse_url($v['finalUrl']);
            if (($u['scheme'] ?? '') !== 'https') {
                continue;
            }
            $host = ($u['host'] ?? '').(isset($u['port']) ? ':'.$u['port'] : '');
            $hostCounts[$host] = ($hostCounts[$host] ?? 0) + 1;
        }
        $canonicalHost = null;
        $bestCount = 0;
        foreach ($hostCounts as $host => $count) {
            if ($count > $bestCount) {
                $canonicalHost = (string) $host;
                $bestCount = $count;
            }
        }

        $issues = [];
        $singleHop = $canonicalHost !== null;
        foreach ($variants as $v) {
            if (isset($v['error']) && $v['finalStatus'] === null) {
                $issues[] = "{$v['url']} could not be fetched: {$v['error']}";

                // Unreachable http variants are common (port 80 closed) — not a hop failure.
                continue;
            }
            if (isset($v['error'])) {
                $issues[] = "{$v['url']}: {$v['error']}";
            }
            $finalUrlObj = parse_url($v['finalUrl']);
            if (($finalUrlObj['scheme'] ?? '') !== 'https') {
                $issues[] = "{$v['url']} does not end on HTTPS (final: {$v['finalUrl']})";
                $singleHop = false;

                continue;
            }
            $finalHost = ($finalUrlObj['host'] ?? '').(isset($finalUrlObj['port']) ? ':'.$finalUrlObj['port'] : '');
            if ($canonicalHost !== null && $finalHost !== $canonicalHost) {
                $issues[] = "{$v['url']} resolves to {$finalHost} instead of canonical {$canonicalHost}";
                $singleHop = false;
            }
            $redirectCount = count(array_filter($v['chain'], static fn (array $h): bool => self::isRedirect($h['status'])));
            if ($redirectCount > 1) {
                $chainStr = implode(' -> ', array_map(static fn (array $h): string => "{$h['url']} [{$h['status']}]", $v['chain']));
                $issues[] = "{$v['url']} needs {$redirectCount} redirects to reach {$v['finalUrl']} (chain: {$chainStr})";
                $singleHop = false;
            }
        }
        if ($canonicalHost === null) {
            $issues[] = 'No variant resolves to an HTTPS destination';
        }

        return ['variants' => $variants, 'canonicalHost' => $canonicalHost, 'singleHop' => $singleHop, 'issues' => $issues];
    }
}
