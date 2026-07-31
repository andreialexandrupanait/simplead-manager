<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Jobs\CheckWooHealth;
use App\Models\Site;
use App\Models\SitePlugin;
use App\Services\Security\SsrfGuard;
use App\Services\WordPressApiServiceFactory;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Faza 5.2 — WooCommerce store-health alerting (conditional).
 *
 * Detection is free (SitePlugin inventory), so these tests wire an active
 * 'woocommerce' plugin to bring a site into the sweep and mock the connector's
 * /woo-health payload. Alert side effects are asserted via the in-app notification
 * the slim path always writes for the site owner, independent of Slack channels.
 */
class CheckWooHealthTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A connected site with an active WooCommerce plugin — the only sites the sweep
     * touches.
     */
    private function wooSite(): Site
    {
        $site = Site::factory()->create(['is_connected' => true]);
        SitePlugin::factory()->create([
            'site_id' => $site->id,
            'slug' => 'woocommerce',
            'name' => 'WooCommerce',
            'file' => 'woocommerce/woocommerce.php',
            'is_active' => true,
        ]);

        return $site;
    }

    /**
     * Bind a connector whose /woo-health returns the given decoded body.
     */
    private function bindWooHealth(array $body): void
    {
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('request')->willReturn(
            new Response(new Psr7Response(200, [], json_encode(array_merge(['success' => true], $body))))
        );

        $this->app->instance(
            WordPressApiServiceFactory::class,
            $this->createMockApiFactory($api)
        );
    }

    private function applicableBody(array $overrides = []): array
    {
        return array_merge([
            'applicable' => true,
            'pending_hours' => 24,
            'pending_orders_count' => 0,
            'pending_over_threshold' => false,
            'scheduled_sales_cron_present' => true,
            'gateways' => [
                ['id' => 'stripe', 'title' => 'Stripe', 'enabled' => true, 'available' => true],
            ],
        ], $overrides);
    }

    /**
     * SPEC §5.4 lists "checkout returnează 200" first among the store signals, and
     * it was the one that never worked: checkout_status was hard-coded to null with
     * a note saying a Manager-side probe was "an optional future signal". The
     * evaluation logic for it existed and was unreachable.
     *
     * The URL has to come from the connector — guessing "/checkout/" 404s on every
     * store with localised permalinks, and a guessed 404 would be recorded as a
     * broken checkout.
     */
    public function test_checkout_is_probed_from_outside_and_recorded(): void
    {
        Queue::fake();
        $site = $this->wooSite();

        $this->bindWooHealth($this->applicableBody([
            'checkout_url' => 'https://shop.example.com/finalizare-comanda/',
        ]));

        Http::fake(['https://shop.example.com/*' => Http::response('', 200)]);
        $this->fakeSsrfGuard();

        (new CheckWooHealth)->handle();

        $this->assertDatabaseHas('woo_checks', [
            'site_id' => $site->id,
            'checkout_status' => 200,
        ]);
    }

    public function test_a_broken_checkout_is_recorded_as_such(): void
    {
        Queue::fake();
        $site = $this->wooSite();

        $this->bindWooHealth($this->applicableBody([
            'checkout_url' => 'https://shop.example.com/checkout/',
        ]));

        Http::fake(['https://shop.example.com/*' => Http::response('Fatal error', 500)]);
        $this->fakeSsrfGuard();

        (new CheckWooHealth)->handle();

        $this->assertDatabaseHas('woo_checks', [
            'site_id' => $site->id,
            'checkout_status' => 500,
        ]);
    }

    /**
     * A connector too old to send the URL must leave the signal at "not probed"
     * rather than guessing — null never counts against the store.
     */
    public function test_no_checkout_url_leaves_the_signal_unprobed(): void
    {
        Queue::fake();
        $site = $this->wooSite();

        $this->bindWooHealth($this->applicableBody());

        (new CheckWooHealth)->handle();

        $this->assertDatabaseHas('woo_checks', [
            'site_id' => $site->id,
            'checkout_status' => null,
        ]);
    }

    /**
     * The probe is a Manager-side fetch of a site-supplied URL, so it goes through
     * the SSRF guard. Tests stub the DNS half so no lookup happens.
     */
    private function fakeSsrfGuard(): void
    {
        $this->app->instance(SsrfGuard::class, new class extends SsrfGuard
        {
            public function assertPublicUrl(string $url): void {}
        });
    }

    public function test_gateway_down_maps_to_red_critical_alert(): void
    {
        Queue::fake();
        $site = $this->wooSite();

        // An enabled gateway that is not available == a payment gateway down.
        $this->bindWooHealth($this->applicableBody([
            'gateways' => [
                ['id' => 'stripe', 'title' => 'Stripe', 'enabled' => true, 'available' => false],
            ],
        ]));

        (new CheckWooHealth)->handle();

        $this->assertDatabaseHas('woo_checks', [
            'site_id' => $site->id,
            'applicable' => true,
            'gateways_ok' => false,
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $site->user_id,
            'type' => 'critical',
        ]);
    }

    public function test_pending_orders_over_threshold_maps_to_yellow_warning(): void
    {
        Queue::fake();
        $site = $this->wooSite();

        $this->bindWooHealth($this->applicableBody([
            'pending_orders_count' => 7,
            'pending_over_threshold' => true,
        ]));

        (new CheckWooHealth)->handle();

        $this->assertDatabaseHas('woo_checks', [
            'site_id' => $site->id,
            'gateways_ok' => true,
            'pending_over_threshold' => true,
            'pending_orders_count' => 7,
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $site->user_id,
            'type' => 'warning',
        ]);
    }

    public function test_missing_scheduled_sales_cron_maps_to_yellow_warning(): void
    {
        Queue::fake();
        $site = $this->wooSite();

        $this->bindWooHealth($this->applicableBody([
            'scheduled_sales_cron_present' => false,
        ]));

        (new CheckWooHealth)->handle();

        $this->assertDatabaseHas('woo_checks', [
            'site_id' => $site->id,
            'discount_cron_scheduled' => false,
        ]);
        $this->assertDatabaseHas('in_app_notifications', [
            'user_id' => $site->user_id,
            'type' => 'warning',
        ]);
    }

    public function test_healthy_store_stores_green_without_alert(): void
    {
        Queue::fake();
        $site = $this->wooSite();

        $this->bindWooHealth($this->applicableBody());

        (new CheckWooHealth)->handle();

        $this->assertDatabaseHas('woo_checks', [
            'site_id' => $site->id,
            'applicable' => true,
            'gateways_ok' => true,
            'pending_over_threshold' => false,
            'discount_cron_scheduled' => true,
        ]);
        $this->assertDatabaseCount('in_app_notifications', 0);
    }

    public function test_site_without_woocommerce_is_skipped_cleanly(): void
    {
        Queue::fake();

        // Connected site, but NO active WooCommerce plugin — must never be queried,
        // never stored, never alerted.
        $site = Site::factory()->create(['is_connected' => true]);
        SitePlugin::factory()->create([
            'site_id' => $site->id,
            'slug' => 'yoast-seo',
            'is_active' => true,
        ]);

        // Bind an API that would fail loudly if the sweep ever called it.
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->expects($this->never())->method('request');
        $this->app->instance(
            WordPressApiServiceFactory::class,
            $this->createMockApiFactory($api)
        );

        (new CheckWooHealth)->handle();

        $this->assertDatabaseCount('woo_checks', 0);
        $this->assertDatabaseCount('in_app_notifications', 0);
    }

    public function test_connector_reports_not_applicable_stores_without_alert(): void
    {
        Queue::fake();
        $site = $this->wooSite();

        // Woo deactivated since the last sync: connector answers applicable=false.
        $this->bindWooHealth(['applicable' => false]);

        (new CheckWooHealth)->handle();

        $this->assertDatabaseHas('woo_checks', [
            'site_id' => $site->id,
            'applicable' => false,
        ]);
        $this->assertDatabaseCount('in_app_notifications', 0);
    }

    public function test_unresolved_red_is_not_realerted_on_the_next_run(): void
    {
        Queue::fake();
        $site = $this->wooSite();

        $this->bindWooHealth($this->applicableBody([
            'gateways' => [
                ['id' => 'stripe', 'title' => 'Stripe', 'enabled' => true, 'available' => false],
            ],
        ]));

        (new CheckWooHealth)->handle();
        $this->assertDatabaseCount('in_app_notifications', 1);

        // Same broken state on the next daily sweep — the colour did not change, so
        // no repeat alert.
        (new CheckWooHealth)->handle();
        $this->assertDatabaseCount('in_app_notifications', 1);
    }
}
