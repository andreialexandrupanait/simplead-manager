<?php

declare(strict_types=1);

namespace Tests\Feature\Dispatchers;

use App\Dispatchers\DataSyncDispatcher;
use App\Jobs\FetchAnalyticsData;
use App\Jobs\FetchSearchConsoleData;
use App\Models\AnalyticsConnection;
use App\Models\GoogleConnection;
use App\Models\SearchConsoleConnection;
use App\Models\Site;
use App\Services\CircuitBreakerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P2-50: the Google dispatcher kept re-dispatching fetches for dead/disconnected
 * connections, and circuit-breaker failures were recorded but never gated
 * dispatch. It must now skip inactive Google accounts and connections whose
 * domain breaker is open.
 */
class DataSyncDispatcherGatingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function analyticsConnection(GoogleConnection $google, Site $site): AnalyticsConnection
    {
        return AnalyticsConnection::create([
            'site_id' => $site->id,
            'google_connection_id' => $google->id,
            'property_id' => 'properties/123',
            'property_name' => 'Test Property',
            'is_active' => true,
            'next_sync_at' => null,
            'interval_minutes' => 60,
        ]);
    }

    public function test_healthy_connection_is_dispatched(): void
    {
        $google = GoogleConnection::factory()->create(['is_active' => true]);
        $site = Site::factory()->create();
        $this->analyticsConnection($google, $site);

        (new DataSyncDispatcher)();

        Queue::assertPushed(FetchAnalyticsData::class);
    }

    public function test_inactive_google_connection_is_not_dispatched(): void
    {
        $google = GoogleConnection::factory()->create(['is_active' => false]);
        $site = Site::factory()->create();
        $this->analyticsConnection($google, $site);

        (new DataSyncDispatcher)();

        Queue::assertNotPushed(FetchAnalyticsData::class);
    }

    public function test_open_analytics_breaker_is_not_dispatched(): void
    {
        $google = GoogleConnection::factory()->create(['is_active' => true]);
        $site = Site::factory()->create();
        $this->analyticsConnection($google, $site);

        // Trip the analytics domain breaker (threshold is 3 failures).
        for ($i = 0; $i < 3; $i++) {
            CircuitBreakerService::recordFailure($site, 'ga down', CircuitBreakerService::DOMAIN_ANALYTICS);
        }

        (new DataSyncDispatcher)();

        Queue::assertNotPushed(FetchAnalyticsData::class);
    }

    public function test_open_search_console_breaker_is_not_dispatched(): void
    {
        $google = GoogleConnection::factory()->create(['is_active' => true]);
        $site = Site::factory()->create();

        SearchConsoleConnection::create([
            'site_id' => $site->id,
            'google_connection_id' => $google->id,
            'property_url' => 'https://example.com/',
            'property_type' => 'url_prefix',
            'is_active' => true,
            'next_sync_at' => null,
            'interval_minutes' => 60,
        ]);

        for ($i = 0; $i < 3; $i++) {
            CircuitBreakerService::recordFailure($site, 'gsc down', CircuitBreakerService::DOMAIN_SEARCH_CONSOLE);
        }

        (new DataSyncDispatcher)();

        Queue::assertNotPushed(FetchSearchConsoleData::class);
    }
}
