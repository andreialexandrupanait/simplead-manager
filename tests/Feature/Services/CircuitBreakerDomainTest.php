<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Site;
use App\Services\CircuitBreakerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * E-13: third-party (analytics / search console) and SEO failures must never
 * trip the shared site breaker, because that breaker's is_monitoring_disabled
 * flag is what stops the site's backups and uptime monitoring.
 */
class CircuitBreakerDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_seo_failures_never_open_the_shared_breaker_or_disable_backups(): void
    {
        $site = Site::factory()->create();

        // Far more than the 3-failure threshold and the 3-breaks/24h kill switch.
        for ($i = 0; $i < 12; $i++) {
            CircuitBreakerService::recordFailure($site, 'crawl failed', CircuitBreakerService::DOMAIN_SEO);
        }

        $state = $site->healthState()->first();
        $this->assertSame('closed', $state->circuit_state);
        $this->assertFalse($state->is_monitoring_disabled);
        $this->assertSame(0, $state->consecutive_failures);
        // Recorded per-domain for observability only.
        $this->assertSame(12, $state->domain_breakers['seo']['consecutive_failures']);
    }

    public function test_analytics_failures_are_isolated_from_search_console_and_core(): void
    {
        $site = Site::factory()->create();

        CircuitBreakerService::recordFailure($site, 'ga down', CircuitBreakerService::DOMAIN_ANALYTICS);
        CircuitBreakerService::recordFailure($site, 'ga down', CircuitBreakerService::DOMAIN_ANALYTICS);
        CircuitBreakerService::recordSuccess($site, CircuitBreakerService::DOMAIN_ANALYTICS);

        $state = $site->healthState()->first();
        $this->assertFalse($state->is_monitoring_disabled);
        $this->assertSame(0, $state->domain_breakers['analytics']['consecutive_failures']);
        $this->assertArrayNotHasKey('search_console', $state->domain_breakers ?? []);
    }

    public function test_core_connector_failures_still_open_the_breaker(): void
    {
        $site = Site::factory()->create();

        for ($i = 0; $i < 3; $i++) {
            CircuitBreakerService::recordFailure($site, 'connector unreachable');
        }

        $state = $site->healthState()->first();
        $this->assertSame('open', $state->circuit_state);
    }
}
