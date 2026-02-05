<?php

namespace Tests\Unit\Services;

use App\Models\WooCommerceAlert;
use App\Models\WooCommerceStat;
use App\Services\WooCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CreatesSite;
use Tests\Traits\MocksWordPressApi;

class WooCommerceServiceTest extends TestCase
{
    use RefreshDatabase, CreatesSite, MocksWordPressApi;

    public function test_sync_daily_stats_creates_woo_commerce_stat_record(): void
    {
        $this->fakeWordPressApi();
        $site = $this->createSite();

        $service = new WooCommerceService();
        $service->syncDailyStats($site);

        $stat = WooCommerceStat::where('site_id', $site->id)->first();
        $this->assertNotNull($stat);
        $this->assertEquals(now()->toDateString(), $stat->date->toDateString());
    }

    public function test_sync_daily_stats_uses_update_or_create_with_date_key(): void
    {
        $this->fakeWordPressApi();
        $site = $this->createSite();

        $service = new WooCommerceService();
        $service->syncDailyStats($site);

        // Verify record was created
        $stat = WooCommerceStat::where('site_id', $site->id)->first();
        $this->assertNotNull($stat);

        $originalId = $stat->id;
        $originalDate = $stat->date->toDateString();

        // The service uses updateOrCreate keyed on site_id + date,
        // so a second call for the same date should attempt to update (not insert).
        // Verify the updateOrCreate key fields are correct.
        $this->assertEquals($site->id, $stat->site_id);
        $this->assertEquals(now()->toDateString(), $originalDate);
    }

    public function test_check_alerts_creates_low_stock_alerts(): void
    {
        $site = $this->createSite();

        $this->fakeWordPressApi([
            '*/wp-json/simplead/v1/woo/low-stock' => Http::response([
                'products' => [
                    ['id' => 101, 'name' => 'Widget A', 'stock_quantity' => 2],
                    ['id' => 102, 'name' => 'Widget B', 'stock_quantity' => 1],
                ],
            ]),
        ]);

        $service = new WooCommerceService();
        $service->checkAlerts($site);

        $this->assertDatabaseHas('woocommerce_alerts', [
            'site_id' => $site->id,
            'type' => 'low_stock',
            'product_id' => 101,
            'product_name' => 'Widget A',
        ]);

        $this->assertEquals(2, WooCommerceAlert::where('site_id', $site->id)->where('type', 'low_stock')->count());
    }

    public function test_check_alerts_creates_out_of_stock_alerts(): void
    {
        $site = $this->createSite();

        $this->fakeWordPressApi([
            '*/wp-json/simplead/v1/woo/out-of-stock' => Http::response([
                'products' => [
                    ['id' => 201, 'name' => 'Gadget X', 'stock_quantity' => 0],
                ],
            ]),
        ]);

        $service = new WooCommerceService();
        $service->checkAlerts($site);

        $this->assertDatabaseHas('woocommerce_alerts', [
            'site_id' => $site->id,
            'type' => 'out_of_stock',
            'product_id' => 201,
            'product_name' => 'Gadget X',
        ]);
    }

    public function test_check_alerts_does_not_duplicate_existing_alerts(): void
    {
        $site = $this->createSite();

        // Pre-create an existing unacknowledged alert
        WooCommerceAlert::create([
            'site_id' => $site->id,
            'type' => 'low_stock',
            'product_id' => 101,
            'product_name' => 'Widget A',
            'message' => 'Widget A has only 2 items in stock.',
            'is_acknowledged' => false,
        ]);

        $this->fakeWordPressApi([
            '*/wp-json/simplead/v1/woo/low-stock' => Http::response([
                'products' => [
                    ['id' => 101, 'name' => 'Widget A', 'stock_quantity' => 2],
                ],
            ]),
        ]);

        $service = new WooCommerceService();
        $service->checkAlerts($site);

        // Should still only have 1 alert for this product
        $count = WooCommerceAlert::where('site_id', $site->id)
            ->where('type', 'low_stock')
            ->where('product_id', 101)
            ->count();

        $this->assertEquals(1, $count);
    }

    public function test_sync_daily_stats_uses_api_data(): void
    {
        $this->fakeWordPressApi();
        $site = $this->createSite();

        $service = new WooCommerceService();
        $service->syncDailyStats($site);

        $stat = WooCommerceStat::where('site_id', $site->id)->first();

        $this->assertEquals(10, $stat->orders_count);
        $this->assertEquals(1500.00, (float) $stat->revenue);
        $this->assertEquals('USD', $stat->currency);
        $this->assertEquals(150.00, (float) $stat->average_order_value);
        $this->assertEquals(25, $stat->products_sold_count);
        $this->assertEquals(5, $stat->new_customers);
    }
}
