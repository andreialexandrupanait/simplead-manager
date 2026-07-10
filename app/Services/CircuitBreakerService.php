<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Site;
use App\Models\SiteHealthState;
use Illuminate\Support\Facades\Log;

class CircuitBreakerService
{
    private const FAILURE_THRESHOLD = 3;

    private const OPEN_DURATION_MINUTES = 60;

    private const MAX_BREAKS_PER_24H = 3;

    /**
     * The shared site-level breaker (circuit_state / is_monitoring_disabled) is a
     * connector-reachability signal and only these domains may drive it. Every
     * other domain (analytics, search_console, seo) is isolated in
     * domain_breakers so a failing third-party API or crawl can never disable a
     * site's backups or uptime monitoring.
     */
    public const DOMAIN_CORE = 'core';

    public const DOMAIN_ANALYTICS = 'analytics';

    public const DOMAIN_SEARCH_CONSOLE = 'search_console';

    public const DOMAIN_SEO = 'seo';

    /**
     * Record a successful job execution for a site.
     */
    public static function recordSuccess(Site $site, string $domain = self::DOMAIN_CORE): void
    {
        if ($domain !== self::DOMAIN_CORE) {
            static::recordDomainSuccess($site, $domain);

            return;
        }

        $state = static::getOrCreateState($site);

        if ($state->isHalfOpen()) {
            // Test job succeeded — close the circuit
            $state->update([
                'consecutive_failures' => 0,
                'circuit_state' => 'closed',
                'circuit_opened_at' => null,
                'last_failure_at' => null,
                'last_failure_reason' => null,
            ]);
            Log::info("Circuit breaker closed for site {$site->id} ({$site->name})");
        } elseif ($state->consecutive_failures > 0) {
            // Reset failure count on success
            $state->update(['consecutive_failures' => 0]);
        }
    }

    /**
     * Record a failed job execution for a site.
     */
    public static function recordFailure(Site $site, string $reason = '', string $domain = self::DOMAIN_CORE): void
    {
        if ($domain !== self::DOMAIN_CORE) {
            static::recordDomainFailure($site, $domain, $reason);

            return;
        }

        $state = static::getOrCreateState($site);

        // Reset 24h counter if period has elapsed
        if ($state->circuit_breaks_reset_at && $state->circuit_breaks_reset_at->isPast()) {
            $state->update([
                'circuit_breaks_last_24h' => 0,
                'circuit_breaks_reset_at' => null,
            ]);
            $state->refresh();
        }

        $failures = $state->consecutive_failures + 1;
        $updates = [
            'consecutive_failures' => $failures,
            'last_failure_at' => now(),
            'last_failure_reason' => mb_substr($reason, 0, 255),
        ];

        if ($state->isHalfOpen()) {
            // Test job failed — reopen circuit
            $breaksToday = $state->circuit_breaks_last_24h + 1;
            $updates['circuit_state'] = 'open';
            $updates['circuit_opened_at'] = now();
            $updates['circuit_breaks_last_24h'] = $breaksToday;

            if (! $state->circuit_breaks_reset_at) {
                $updates['circuit_breaks_reset_at'] = now()->addDay();
            }

            // Disable monitoring if too many breaks in 24h
            if ($breaksToday >= self::MAX_BREAKS_PER_24H) {
                $updates['is_monitoring_disabled'] = true;
                Log::warning("Monitoring disabled for site {$site->id} ({$site->name}) — {$breaksToday} circuit breaks in 24h");
            }
        } elseif ($state->isClosed() && $failures >= self::FAILURE_THRESHOLD) {
            // Open circuit after threshold consecutive failures
            $breaksToday = $state->circuit_breaks_last_24h + 1;
            $updates['circuit_state'] = 'open';
            $updates['circuit_opened_at'] = now();
            $updates['circuit_breaks_last_24h'] = $breaksToday;

            if (! $state->circuit_breaks_reset_at) {
                $updates['circuit_breaks_reset_at'] = now()->addDay();
            }

            if ($breaksToday >= self::MAX_BREAKS_PER_24H) {
                $updates['is_monitoring_disabled'] = true;
                Log::warning("Monitoring disabled for site {$site->id} ({$site->name}) — {$breaksToday} circuit breaks in 24h");
            }

            Log::info("Circuit breaker opened for site {$site->id} ({$site->name}) after {$failures} failures");
        }

        $state->update($updates);
    }

    /**
     * Check all open circuits and transition to half_open if enough time has passed.
     * Called by dispatchers before querying for due jobs.
     */
    public static function checkHalfOpen(): void
    {
        SiteHealthState::where('circuit_state', 'open')
            ->where('is_monitoring_disabled', false)
            ->where('circuit_opened_at', '<=', now()->subMinutes(self::OPEN_DURATION_MINUTES))
            ->update(['circuit_state' => 'half_open']);
    }

    /**
     * Manually re-enable monitoring for a site (after admin review).
     */
    public static function reEnable(Site $site): void
    {
        $state = static::getOrCreateState($site);
        $state->update([
            'consecutive_failures' => 0,
            'circuit_state' => 'closed',
            'circuit_opened_at' => null,
            'circuit_breaks_last_24h' => 0,
            'circuit_breaks_reset_at' => null,
            'is_monitoring_disabled' => false,
            'last_failure_at' => null,
            'last_failure_reason' => null,
        ]);
    }

    /**
     * Record a per-domain failure without touching the shared site breaker.
     * Kept purely for observability + future per-domain throttling; it never
     * opens the circuit or disables monitoring/backups.
     */
    protected static function recordDomainFailure(Site $site, string $domain, string $reason): void
    {
        $state = static::getOrCreateState($site);
        $breakers = $state->domain_breakers ?? [];
        $entry = $breakers[$domain] ?? ['consecutive_failures' => 0];

        $breakers[$domain] = [
            'consecutive_failures' => ($entry['consecutive_failures'] ?? 0) + 1,
            'last_failure_at' => now()->toIso8601String(),
            'last_failure_reason' => mb_substr($reason, 0, 255),
        ];

        $state->update(['domain_breakers' => $breakers]);
    }

    /**
     * Clear a per-domain failure streak on success.
     */
    protected static function recordDomainSuccess(Site $site, string $domain): void
    {
        $state = static::getOrCreateState($site);
        $breakers = $state->domain_breakers ?? [];

        if (! empty($breakers[$domain]['consecutive_failures'])) {
            $breakers[$domain]['consecutive_failures'] = 0;
            $state->update(['domain_breakers' => $breakers]);
        }
    }

    /**
     * Get or create health state for a site.
     */
    protected static function getOrCreateState(Site $site): SiteHealthState
    {
        return SiteHealthState::firstOrCreate(
            ['site_id' => $site->id],
            ['circuit_state' => 'closed']
        );
    }
}
