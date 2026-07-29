<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Faza 6, SPEC §7.4 etapa 1 — post-update smoke check.
 *
 * Pure Manager-side signal collection: performs a direct public HTTP GET against
 * the site's real public URL ($site->url) and evaluates four cheap "is the site
 * still alive and roughly the same" signals on the returned HTML. It deliberately
 * does NOT go through the WordPress connector — a connector/loopback request from
 * the WP host itself is answered by Cloudflare with a 403 (see CLAUDE.md), so the
 * only way to observe what a real visitor sees is to hit the public URL directly.
 *
 * The four signals (SPEC §7.4 etapa 1):
 *   1. HTTP 200          — anything else is an immediate fail.
 *   2. No PHP fatal       — the HTML must not contain "Fatal error" / "Parse error"
 *                           (case-insensitive), which a broken update prints inline.
 *   3. Canary present     — a caller-supplied selector fragment (an id/class marker)
 *                           must appear in the HTML; absent a selector we fall back
 *                           to the presence of a "<body" tag as a minimal liveness
 *                           marker.
 *   4. DOM node count     — approximated as substr_count($html, '<'). Against a
 *                           known reference it must stay within ±20% (a large swing
 *                           means the page collapsed or exploded). With no reference
 *                           yet this is a BASELINE run: the signal passes and the
 *                           measured value is returned as the new reference to store.
 *
 * Fail-safe: any HTTP/timeout exception yields passed=false with a single
 * 'unreachable' signal — an unreachable site after an update is never "healthy".
 */
class UpdateSmokeCheckService
{
    /** Request timeout (seconds) for the public GET. */
    private const HTTP_TIMEOUT = 15;

    /** Allowed relative deviation of the DOM node count from its reference (±20%). */
    private const DOM_TOLERANCE = 0.20;

    /**
     * Run the smoke check against the site's public URL.
     *
     * @param  Site  $site  the site to probe ($site->url is the public URL hit).
     * @param  int|null  $domReference  previously stored DOM node count to compare
     *                                  against; null triggers a baseline run.
     * @param  string|null  $canarySelector  an HTML fragment (id/class/markup) that
     *                                       must be present; null falls back to '<body'.
     * @return array{passed: bool, signals: array<int, array{name: string, passed: bool, detail: string}>, dom_reference: int|null}
     */
    public function run(Site $site, ?int $domReference = null, ?string $canarySelector = null): array
    {
        try {
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->withOptions(['allow_redirects' => true])
                ->get($site->url);
        } catch (\Throwable $e) {
            Log::warning("Smoke check unreachable for site {$site->id} ({$site->url}): {$e->getMessage()}");

            return [
                'passed' => false,
                'signals' => [[
                    'name' => 'unreachable',
                    'passed' => false,
                    'detail' => 'Public URL could not be fetched: '.$e->getMessage(),
                ]],
                'dom_reference' => $domReference,
            ];
        }

        $status = $response->status();
        $html = $response->body();

        $signals = [];

        // Signal 1 — HTTP 200.
        $statusOk = $status === 200;
        $signals[] = [
            'name' => 'status',
            'passed' => $statusOk,
            'detail' => "HTTP {$status}",
        ];

        // Signal 2 — no PHP fatal / parse error in the rendered HTML.
        $fatalFound = null;
        foreach (['Fatal error', 'Parse error'] as $needle) {
            if (stripos($html, $needle) !== false) {
                $fatalFound = $needle;
                break;
            }
        }
        $signals[] = [
            'name' => 'no_fatal_error',
            'passed' => $fatalFound === null,
            'detail' => $fatalFound === null
                ? 'No PHP fatal/parse error in HTML'
                : "HTML contains '{$fatalFound}'",
        ];

        // Signal 3 — canary marker present (fallback to a minimal <body liveness marker).
        $canary = ($canarySelector !== null && $canarySelector !== '') ? $canarySelector : '<body';
        $canaryPresent = str_contains($html, $canary);
        $signals[] = [
            'name' => 'canary',
            'passed' => $canaryPresent,
            'detail' => $canaryPresent
                ? "Canary '{$canary}' present"
                : "Canary '{$canary}' missing",
        ];

        // Signal 4 — DOM node count (approx) within tolerance of the reference, or baseline.
        $domNodes = substr_count($html, '<');
        $newReference = $domReference;

        if ($domReference === null) {
            // Baseline run: nothing to compare against yet — record the measurement.
            $newReference = $domNodes;
            $signals[] = [
                'name' => 'dom_nodes',
                'passed' => true,
                'detail' => "Baseline captured: {$domNodes} nodes (no prior reference)",
            ];
        } else {
            $lower = (int) floor($domReference * (1 - self::DOM_TOLERANCE));
            $upper = (int) ceil($domReference * (1 + self::DOM_TOLERANCE));
            $withinTolerance = $domNodes >= $lower && $domNodes <= $upper;
            $signals[] = [
                'name' => 'dom_nodes',
                'passed' => $withinTolerance,
                'detail' => $withinTolerance
                    ? "DOM nodes {$domNodes} within ±20% of {$domReference}"
                    : "DOM nodes {$domNodes} outside ±20% of {$domReference} (allowed {$lower}–{$upper})",
            ];
        }

        // All four signals are mandatory; the check passes only when every one passes.
        $passed = true;
        foreach ($signals as $signal) {
            if (! $signal['passed']) {
                $passed = false;
                break;
            }
        }

        return [
            'passed' => $passed,
            'signals' => $signals,
            'dom_reference' => $newReference,
        ];
    }
}
