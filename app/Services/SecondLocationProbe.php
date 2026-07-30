<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * SPEC §5.1 — the second opinion on "is this site actually down?".
 *
 * Manager's uptime checks all leave from one Hetzner box. When that box's uplink
 * stutters, every site looks down at once and the fleet gets a wall of false
 * incidents. This asks a Cloudflare Worker (see workers/uptime-probe/) — a
 * different network, a different vantage point — the same question.
 *
 * FAIL-OPEN BY DESIGN: an unreachable, unconfigured or erroring probe returns
 * null, which callers must treat as "no second opinion", never as "the site is
 * fine". Silence from the probe must not be able to suppress a real incident.
 */
class SecondLocationProbe
{
    public function enabled(): bool
    {
        return $this->url() !== '' && $this->secret() !== '';
    }

    /**
     * Ask the second location whether it can reach $url.
     *
     * @return array{ok: bool, status: int|null, ms: int|null, colo: string|null}|null
     *                                                                                 null when there is no usable second opinion
     */
    public function check(string $url): ?array
    {
        if (! $this->enabled() || trim($url) === '') {
            return null;
        }

        try {
            $response = Http::timeout((int) config('monitoring.second_location.timeout', 12))
                ->withHeaders(['x-probe-secret' => $this->secret()])
                ->get(rtrim($this->url(), '/').'/probe', ['url' => $url]);

            if (! $response->successful()) {
                Log::warning("Second-location probe returned HTTP {$response->status()} for {$url}");

                return null;
            }

            $body = $response->json();

            if (! is_array($body) || ! array_key_exists('ok', $body)) {
                return null;
            }

            return [
                'ok' => (bool) $body['ok'],
                'status' => isset($body['status']) ? (int) $body['status'] : null,
                'ms' => isset($body['ms']) ? (int) $body['ms'] : null,
                'colo' => isset($body['colo']) ? (string) $body['colo'] : null,
            ];
        } catch (\Throwable $e) {
            Log::warning("Second-location probe unavailable for {$url}: {$e->getMessage()}");

            return null;
        }
    }

    private function url(): string
    {
        return trim((string) config('monitoring.second_location.url', ''));
    }

    private function secret(): string
    {
        return trim((string) config('monitoring.second_location.secret', ''));
    }
}
