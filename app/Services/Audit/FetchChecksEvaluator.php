<?php

declare(strict_types=1);

namespace App\Services\Audit;

use App\DTOs\Audit\V2Eval;
use App\Enums\CheckState;
use App\Services\Audit\Http\HeadersCollector;
use App\Services\Audit\Http\LlmsTxtCollector;
use App\Services\Audit\Http\RedirectsCollector;
use App\Services\Audit\Http\RobotsCollector;
use App\Services\Audit\Http\UrlHelper;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The v2 checks proven by direct fetch (port of src/lib/evaluation/v2/fetch-checks.ts):
 *  - 6.1  robots.txt allows the 8 AI user-agents
 *  - 6.2  the server/WAF does not block the AI user-agents (real request per UA)
 *  - 6.5  llms.txt exists and is clean
 *  - 3.1  a single canonical host, a single 301 (4 http/https × www/non-www variants)
 *  - 3.8  a non-existent URL answers with a real 404
 *  - 3.10 (evidence) the homepage headers, alongside the SF verdict
 *
 * A network failure never fabricates a verdict: the check stays state=null with
 * the error in evidence (the auditor decides).
 */
final class FetchChecksEvaluator
{
    /** The 8 AI user-agents of the v2 methodology (METODOLOGIE.md, section 06). */
    public const AI_USER_AGENTS_V2 = [
        'GPTBot',
        'OAI-SearchBot',
        'ChatGPT-User',
        'ClaudeBot',
        'Claude-User',
        'PerplexityBot',
        'Google-Extended',
        'bingbot',
    ];

    /** The UA strings used for the real request (6.2) — the curl -A equivalent. */
    public const AI_UA_STRINGS = [
        'GPTBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; GPTBot/1.2; +https://openai.com/gptbot',
        'OAI-SearchBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; OAI-SearchBot/1.0; +https://openai.com/searchbot',
        'ChatGPT-User' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko); compatible; ChatGPT-User/1.0; +https://openai.com/bot',
        'ClaudeBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)',
        'Claude-User' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; Claude-User/1.0)',
        'PerplexityBot' => 'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; PerplexityBot/1.0; +https://perplexity.ai/perplexitybot)',
        'Google-Extended' => 'Google-Extended',
        'bingbot' => 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)',
    ];

    /** Statuses treated as "blocked" in the real per-UA test (6.2). */
    private const BLOCKED_STATUSES = [401, 403, 406, 418, 429, 451, 503];

    public function __construct(
        private readonly RobotsCollector $robots = new RobotsCollector,
        private readonly LlmsTxtCollector $llms = new LlmsTxtCollector,
        private readonly RedirectsCollector $redirects = new RedirectsCollector,
        private readonly HeadersCollector $headers = new HeadersCollector,
    ) {}

    private static function failEval(string $context, Throwable $e): V2Eval
    {
        return new V2Eval(null, [
            'error' => "{$context}: {$e->getMessage()}",
            'note' => 'Eroare de colectare — verdictul rămâne al auditorului.',
        ]);
    }

    /** 6.1 — robots.txt allows the 8 AI UAs on "/". */
    public function evalRobotsAiV2(string $origin): V2Eval
    {
        try {
            $robots = $this->robots->collect($origin);
            $parsed = RobotsCollector::parse($robots['found'] ? $robots['raw'] : '');
            $agents = array_map(
                static fn (string $name): array => [
                    'userAgent' => $name,
                    'allowed' => $robots['found'] ? RobotsCollector::isAllowed($parsed, $name, '/') : true,
                ],
                self::AI_USER_AGENTS_V2,
            );
            $blocked = array_values(array_filter($agents, static fn (array $a): bool => ! $a['allowed']));

            return new V2Eval(
                $blocked === [] ? CheckState::Exista : CheckState::NuExista,
                [
                    'note' => $robots['found']
                        ? 'Reguli evaluate per user-agent pe calea „/” din /robots.txt.'
                        : 'robots.txt absent (status '.($robots['status'] ?? 'fără răspuns').') — implicit toți agenții sunt permiși.',
                    'robotsTxt' => ['found' => $robots['found'], 'status' => $robots['status']],
                    'agents' => $agents,
                    'blocati' => array_map(static fn (array $a): string => $a['userAgent'], $blocked),
                    'rawExcerpt' => substr($robots['raw'], 0, 2048),
                ],
            );
        } catch (Throwable $e) {
            return self::failEval('fetch /robots.txt', $e);
        }
    }

    /** 6.2 — real per-UA test (curl -A equivalent): 200 vs 403/challenge. */
    public function evalUaBlockingV2(string $url): V2Eval
    {
        $attempts = [];
        foreach (self::AI_USER_AGENTS_V2 as $name) {
            $uaString = self::AI_UA_STRINGS[$name];
            try {
                $res = Http::withHeaders(['User-Agent' => $uaString])->get($url);
                $attempts[] = [
                    'userAgent' => $name,
                    'uaString' => $uaString,
                    'status' => $res->status(),
                    'blocked' => in_array($res->status(), self::BLOCKED_STATUSES, true),
                ];
            } catch (ConnectionException $e) {
                $attempts[] = ['userAgent' => $name, 'uaString' => $uaString, 'status' => null, 'blocked' => false, 'error' => $e->getMessage()];
            }
        }
        $succeeded = array_values(array_filter($attempts, static fn (array $a): bool => $a['status'] !== null));
        if ($succeeded === []) {
            return self::failEval('test UA AI', new \RuntimeException($attempts[0]['error'] ?? 'niciun răspuns'));
        }
        $blocked = array_values(array_filter(
            $attempts,
            static fn (array $a): bool => $a['blocked'] || ($a['status'] === null),
        ));

        return new V2Eval(
            $blocked === [] ? CheckState::Exista : CheckState::NuExista,
            [
                'note' => 'Request GET real per user-agent AI (User-Agent complet, echivalent curl -A). '
                    .'Google-Extended nu are crawler propriu — testat simetric cu tokenul ca UA, rezultat informativ.',
                'attempts' => $attempts,
                'blocati' => array_map(static fn (array $a): string => $a['userAgent'], $blocked),
            ],
        );
    }

    /** 6.5 — llms.txt exists and looks like llms.txt (not an HTML error page). */
    public function evalLlmsTxtV2(string $origin): V2Eval
    {
        try {
            $llms = $this->llms->collect($origin);

            return new V2Eval(
                $llms['found'] && $llms['looksValid'] ? CheckState::Exista : CheckState::NuExista,
                array_merge([
                    'note' => $llms['found']
                        ? ($llms['looksValid']
                            ? 'llms.txt găsit, cu conținut plauzibil (text/markdown, nu HTML).'
                            : 'llms.txt răspunde 200 dar conținutul nu arată a llms.txt (HTML/gol).')
                        : 'llms.txt absent (status '.($llms['status'] ?? 'fără răspuns').').',
                ], $llms),
            );
        } catch (Throwable $e) {
            return self::failEval('fetch /llms.txt', $e);
        }
    }

    /** 3.1 — single canonical host, one 301 across all 4 variants. */
    public function evalHostCanonicalV2(string $auditUrl): V2Eval
    {
        try {
            $redirects = $this->redirects->collect($auditUrl);
            $pass = $redirects['canonicalHost'] !== null && $redirects['singleHop'];

            return new V2Eval(
                $pass ? CheckState::Exista : CheckState::NuExista,
                [
                    'note' => 'Testul celor 4 variante http/https × www/non-www, cu urmărire manuală a redirecturilor.',
                    'canonicalHost' => $redirects['canonicalHost'],
                    'singleHop' => $redirects['singleHop'],
                    'issues' => $redirects['issues'],
                    'variants' => $redirects['variants'],
                ],
            );
        } catch (Throwable $e) {
            return self::failEval('test variante gazdă', $e);
        }
    }

    /** 3.8 — a non-existent URL answers with a real 404 (not 200/redirect to home). */
    public function evalReal404V2(string $origin, ?string $probePath = null): V2Eval
    {
        $path = $probePath ?? '/simplead-audit-404-proba-'.substr(bin2hex(random_bytes(6)), 0, 10);
        $probeUrl = UrlHelper::resolvePath($origin, $path);
        try {
            $res = Http::get($probeUrl);
            $real404 = $res->status() === 404 || $res->status() === 410;
            $finalUrl = $res->effectiveUri() !== null ? (string) $res->effectiveUri() : $probeUrl;

            return new V2Eval(
                $real404 ? CheckState::Exista : CheckState::NuExista,
                [
                    'note' => 'Fetch pe URL garantat inexistent (SF crawl-uiește doar URL-uri linkuite). '
                        .'Personalizarea vizuală a paginii 404 se confirmă manual.',
                    'probeUrl' => $probeUrl,
                    'status' => $res->status(),
                    'finalUrl' => $finalUrl !== '' ? $finalUrl : $probeUrl,
                ],
            );
        } catch (Throwable $e) {
            return self::failEval("fetch {$probeUrl}", $e);
        }
    }

    /**
     * Run all fetch-based checks for an audit.
     *
     * @return array{results: array<string, V2Eval>, homepageHeaders: array<string, mixed>}
     */
    public function runFetchChecks(string $auditUrl, ?string $notFoundProbePath = null): array
    {
        $origin = UrlHelper::originOf($auditUrl);

        $results = [
            '6.1' => $this->evalRobotsAiV2($origin),
            '6.2' => $this->evalUaBlockingV2($auditUrl),
            '6.5' => $this->evalLlmsTxtV2($origin),
            '3.1' => $this->evalHostCanonicalV2($auditUrl),
            '3.8' => $this->evalReal404V2($origin, $notFoundProbePath),
        ];

        try {
            $homepageHeaders = $this->headers->collect($auditUrl);
        } catch (Throwable $e) {
            $homepageHeaders = ['error' => $e->getMessage()];
        }

        return ['results' => $results, 'homepageHeaders' => $homepageHeaders];
    }
}
