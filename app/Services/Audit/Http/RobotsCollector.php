<?php

declare(strict_types=1);

namespace App\Services\Audit\Http;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * robots.txt collector — fetches /robots.txt, parses agent groups, and decides
 * whether a crawler may access a path. Port of src/lib/collectors/robots.ts.
 */
final class RobotsCollector
{
    public const RAW_TRUNCATE_BYTES = 10 * 1024; // 10KB

    /**
     * Parse robots.txt into user-agent groups + global sitemap directives.
     *
     * @return array{groups: list<array{agents: list<string>, rules: list<array{type: string, pattern: string}>}>, sitemaps: list<string>}
     */
    public static function parse(string $content): array
    {
        $groups = [];
        $sitemaps = [];
        $current = null;
        $lastWasUserAgent = false;

        foreach (preg_split('/\r?\n/', $content) ?: [] as $rawLine) {
            $line = trim(preg_replace('/#.*$/', '', $rawLine) ?? '');
            if ($line === '') {
                continue;
            }
            $colon = strpos($line, ':');
            if ($colon === false) {
                continue;
            }
            $directive = strtolower(trim(substr($line, 0, $colon)));
            $value = trim(substr($line, $colon + 1));

            switch ($directive) {
                case 'user-agent':
                    if (! $lastWasUserAgent || $current === null) {
                        $groups[] = ['agents' => [], 'rules' => []];
                        $current = array_key_last($groups);
                    }
                    if ($value !== '') {
                        $groups[$current]['agents'][] = $value;
                    }
                    $lastWasUserAgent = true;
                    break;
                case 'allow':
                case 'disallow':
                    if ($current !== null) {
                        $groups[$current]['rules'][] = ['type' => $directive, 'pattern' => $value];
                    }
                    $lastWasUserAgent = false;
                    break;
                case 'sitemap':
                    if ($value !== '') {
                        $sitemaps[] = $value;
                    }
                    $lastWasUserAgent = false;
                    break;
                default:
                    // crawl-delay, host, etc. — irrelevant here but they end a UA run
                    $lastWasUserAgent = false;
            }
        }

        return ['groups' => array_values($groups), 'sitemaps' => $sitemaps];
    }

    /** Convert a robots.txt path pattern (supports * and trailing $) to a regex. */
    private static function patternToRegExp(string $pattern): string
    {
        $anchored = false;
        $p = $pattern;
        if (str_ends_with($p, '$')) {
            $anchored = true;
            $p = substr($p, 0, -1);
        }
        // Escape regex specials (NOT '*'), then turn '*' into '.*'.
        $escaped = preg_replace_callback('/[.+?^${}()|\[\]~\\\\]/', static fn (array $m): string => '\\'.$m[0], $p) ?? $p;
        $escaped = str_replace('*', '.*', $escaped);

        return '~^'.$escaped.($anchored ? '$' : '').'~';
    }

    /**
     * Evaluate whether $agentName may fetch $path under the parsed rules.
     * Group selection: most specific matching user-agent token wins ("*" is
     * fallback). Rule selection: longest matching pattern wins; ties → Allow.
     *
     * @param  array{groups: list<array{agents: list<string>, rules: list<array{type: string, pattern: string}>}>, sitemaps: list<string>}  $parsed
     */
    public static function isAllowed(array $parsed, string $agentName, string $path = '/'): bool
    {
        $agentLower = strtolower($agentName);

        $bestLen = -1;
        foreach ($parsed['groups'] as $group) {
            foreach ($group['agents'] as $token) {
                $t = strtolower($token);
                if ($t === '*') {
                    continue;
                }
                if (str_contains($agentLower, $t) && strlen($t) > $bestLen) {
                    $bestLen = strlen($t);
                }
            }
        }

        $rules = [];
        foreach ($parsed['groups'] as $group) {
            $matches = false;
            foreach ($group['agents'] as $token) {
                $t = strtolower($token);
                if ($bestLen >= 0) {
                    if ($t !== '*' && strlen($t) === $bestLen && str_contains($agentLower, $t)) {
                        $matches = true;
                        break;
                    }
                } elseif ($t === '*') {
                    $matches = true;
                    break;
                }
            }
            if ($matches) {
                array_push($rules, ...$group['rules']);
            }
        }
        if ($rules === []) {
            return true;
        }

        $verdict = true;
        $verdictLen = -1;
        foreach ($rules as $rule) {
            if ($rule['pattern'] === '') {
                continue; // "Disallow:" (empty) matches nothing
            }
            if (preg_match(self::patternToRegExp($rule['pattern']), $path) !== 1) {
                continue;
            }
            $len = strlen($rule['pattern']);
            if ($len > $verdictLen || ($len === $verdictLen && $rule['type'] === 'allow')) {
                $verdictLen = $len;
                $verdict = $rule['type'] === 'allow';
            }
        }

        return $verdict;
    }

    /**
     * Fetch /robots.txt. Returns found/status/raw (parsing is the caller's job).
     *
     * @return array{found: bool, status: int|null, raw: string}
     */
    public function collect(string $url): array
    {
        $robotsUrl = UrlHelper::resolvePath($url, '/robots.txt');
        $status = null;
        $body = '';
        try {
            $res = Http::get($robotsUrl);
            $status = $res->status();
            if ($res->successful()) {
                $body = $res->body();
            }
        } catch (ConnectionException) {
            $status = null;
        }

        return [
            'found' => $status === 200,
            'status' => $status,
            'raw' => substr($body, 0, self::RAW_TRUNCATE_BYTES),
        ];
    }
}
