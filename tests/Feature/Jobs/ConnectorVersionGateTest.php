<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Jobs\CheckBrokenLinks;
use App\Jobs\CheckWooHealth;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Services\BrokenLinkChecker;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The Faza-5 endpoints (/woo-health, /content-urls) only exist from connector
 * 2.19.2. On 2026-07-30 production ran the daily Woo sweep against 11 sites
 * still on 2.19.0: every one 404'd, was logged as a connector error and stored
 * as a failed check — noise that looked like a store problem but was really a
 * pending plugin push.
 *
 * A site below the minimum version must now be skipped cleanly: no request, no
 * error row, no log line.
 */
class ConnectorVersionGateTest extends TestCase
{
    use RefreshDatabase;

    private function wooSite(string $connectorVersion): Site
    {
        $site = Site::factory()->create([
            'is_connected' => true,
            'connector_version' => $connectorVersion,
        ]);

        SitePlugin::factory()->create([
            'site_id' => $site->id,
            'slug' => 'woocommerce',
            'name' => 'WooCommerce',
            'file' => 'woocommerce/woocommerce.php',
            'is_active' => true,
        ]);

        return $site;
    }

    public function test_woo_sweep_skips_a_site_on_an_older_connector(): void
    {
        $site = $this->wooSite('2.19.0');

        // Any call to the connector fails the test outright.
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->expects($this->never())->method('request');
        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));

        (new CheckWooHealth)->handle();

        $this->assertDatabaseMissing('woo_checks', ['site_id' => $site->id]);
    }

    public function test_woo_sweep_still_runs_on_a_current_connector(): void
    {
        $site = $this->wooSite('2.19.2');

        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->expects($this->once())->method('request')->willReturn(
            new \Illuminate\Http\Client\Response(
                new \GuzzleHttp\Psr7\Response(200, [], (string) json_encode([
                    'success' => true,
                    'applicable' => true,
                    'pending_hours' => 24,
                    'pending_orders_count' => 0,
                    'pending_over_threshold' => false,
                    'scheduled_sales_cron_present' => true,
                    'gateways' => [
                        ['id' => 'stripe', 'title' => 'Stripe', 'enabled' => true, 'available' => true],
                    ],
                ]))
            )
        );
        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));

        (new CheckWooHealth)->handle();

        $this->assertDatabaseHas('woo_checks', ['site_id' => $site->id, 'applicable' => true]);
    }

    public function test_link_sweep_skips_a_site_on_an_older_connector(): void
    {
        Http::preventStrayRequests();
        $site = Site::factory()->create([
            'is_connected' => true,
            'connector_version' => '2.19.0',
        ]);

        // No Http::fake at all — a stray /content-urls call would throw.
        (new CheckBrokenLinks)->handle(app(BrokenLinkChecker::class));

        $this->assertDatabaseMissing('link_check_runs', ['site_id' => $site->id]);
    }

    public function test_connector_at_least_is_fail_closed_on_a_missing_version(): void
    {
        $site = Site::factory()->create(['connector_version' => null]);

        $this->assertFalse($site->connectorAtLeast('2.19.2'));

        $site->forceFill(['connector_version' => '2.20.0'])->saveQuietly();
        $this->assertTrue($site->fresh()->connectorAtLeast('2.19.2'));
    }
}
