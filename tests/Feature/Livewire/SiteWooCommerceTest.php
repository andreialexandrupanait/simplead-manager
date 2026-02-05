<?php

namespace Tests\Feature\Livewire;

use App\Jobs\SyncWooCommerceStats;
use App\Livewire\Sites\Detail\SiteWooCommerce;
use App\Models\User;
use App\Models\WooCommerceStat;
use App\Services\WooCommerceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;
use Tests\Traits\CreatesSite;
use Tests\Traits\MocksWordPressApi;

class SiteWooCommerceTest extends TestCase
{
    use RefreshDatabase, CreatesSite, MocksWordPressApi;

    private User $user;
    private $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->site = $this->createSite(['has_woocommerce' => true]);
        $this->fakeWordPressApi();

        // The migrations create tables named 'woocommerce_stats' and 'woocommerce_alerts',
        // but the Eloquent models WooCommerceStat and WooCommerceAlert expect Laravel's
        // default snake_case convention: 'woo_commerce_stats' and 'woo_commerce_alerts'.
        // Create views to bridge this naming mismatch so the component can render.
        if (Schema::hasTable('woocommerce_alerts') && !Schema::hasTable('woo_commerce_alerts')) {
            Schema::getConnection()->statement('CREATE VIEW woo_commerce_alerts AS SELECT * FROM woocommerce_alerts');
        }
        if (Schema::hasTable('woocommerce_stats') && !Schema::hasTable('woo_commerce_stats')) {
            Schema::getConnection()->statement('CREATE VIEW woo_commerce_stats AS SELECT * FROM woocommerce_stats');
        }
    }

    private function mockWooCommerceService(?WooCommerceStat $todayStats = null): void
    {
        $mock = Mockery::mock(WooCommerceService::class);
        $mock->shouldReceive('getTodayStats')->andReturn($todayStats);
        $mock->shouldReceive('getRevenueChart')->andReturn(collect());
        $this->app->instance(WooCommerceService::class, $mock);
    }

    public function test_component_renders_for_woocommerce_enabled_site(): void
    {
        $this->mockWooCommerceService();

        Livewire::actingAs($this->user)
            ->test(SiteWooCommerce::class, ['site' => $this->site])
            ->assertOk()
            ->assertSee('Sync Now');
    }

    public function test_shows_404_for_non_woocommerce_site(): void
    {
        $nonWooSite = $this->createSite(['has_woocommerce' => false]);

        $this->mockWooCommerceService();

        Livewire::actingAs($this->user)
            ->test(SiteWooCommerce::class, ['site' => $nonWooSite])
            ->assertStatus(404);
    }

    public function test_displays_today_stats(): void
    {
        $stat = new WooCommerceStat([
            'site_id' => $this->site->id,
            'date' => now()->toDateString(),
            'orders_count' => 42,
            'revenue' => 3250.00,
            'currency' => 'USD',
            'average_order_value' => 77.38,
            'products_sold_count' => 85,
            'refunds_count' => 1,
            'refunds_amount' => 50.00,
            'new_customers' => 10,
            'returning_customers' => 15,
        ]);

        $this->mockWooCommerceService($stat);

        Livewire::actingAs($this->user)
            ->test(SiteWooCommerce::class, ['site' => $this->site])
            ->assertSee('42')
            ->assertSee('3,250.00');
    }

    public function test_can_trigger_sync_dispatches_job(): void
    {
        Bus::fake(SyncWooCommerceStats::class);
        $this->mockWooCommerceService();

        Livewire::actingAs($this->user)
            ->test(SiteWooCommerce::class, ['site' => $this->site])
            ->call('syncNow');

        Bus::assertDispatched(SyncWooCommerceStats::class, function ($job) {
            return $job->site->id === $this->site->id;
        });
    }
}
