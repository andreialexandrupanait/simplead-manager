<?php

namespace App\Services;

use App\Models\Site;
use App\Models\WooCommerceAlert;
use App\Models\WooCommerceStat;
use Illuminate\Support\Collection;

class WooCommerceService
{
    public function syncDailyStats(Site $site): void
    {
        $api = new WordPressApiService($site);
        $data = $api->getWooStats('today');

        WooCommerceStat::updateOrCreate(
            [
                'site_id' => $site->id,
                'date' => now()->toDateString(),
            ],
            [
                'orders_count' => $data['orders_count'] ?? 0,
                'revenue' => $data['revenue'] ?? 0,
                'currency' => $data['currency'] ?? 'USD',
                'average_order_value' => $data['average_order_value'] ?? 0,
                'products_sold_count' => $data['products_sold_count'] ?? 0,
                'refunds_count' => $data['refunds_count'] ?? 0,
                'refunds_amount' => $data['refunds_amount'] ?? 0,
                'new_customers' => $data['new_customers'] ?? 0,
                'returning_customers' => $data['returning_customers'] ?? 0,
            ]
        );
    }

    public function checkAlerts(Site $site): void
    {
        $api = new WordPressApiService($site);

        try {
            $lowStock = $api->getWooLowStock();
            foreach ($lowStock['products'] ?? [] as $product) {
                $exists = WooCommerceAlert::where('site_id', $site->id)
                    ->where('type', 'low_stock')
                    ->where('product_id', $product['id'])
                    ->where('is_acknowledged', false)
                    ->exists();

                if (!$exists) {
                    WooCommerceAlert::create([
                        'site_id' => $site->id,
                        'type' => 'low_stock',
                        'product_id' => $product['id'],
                        'product_name' => $product['name'] ?? 'Unknown',
                        'message' => ($product['name'] ?? 'Product') . ' has only ' . ($product['stock_quantity'] ?? 0) . ' items in stock.',
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Low stock endpoint may not be available
        }

        try {
            $outOfStock = $api->getWooOutOfStock();
            foreach ($outOfStock['products'] ?? [] as $product) {
                $exists = WooCommerceAlert::where('site_id', $site->id)
                    ->where('type', 'out_of_stock')
                    ->where('product_id', $product['id'])
                    ->where('is_acknowledged', false)
                    ->exists();

                if (!$exists) {
                    WooCommerceAlert::create([
                        'site_id' => $site->id,
                        'type' => 'out_of_stock',
                        'product_id' => $product['id'],
                        'product_name' => $product['name'] ?? 'Unknown',
                        'message' => ($product['name'] ?? 'Product') . ' is out of stock.',
                    ]);
                }
            }
        } catch (\Exception $e) {
            // Out of stock endpoint may not be available
        }
    }

    public function getRevenueChart(Site $site, int $days = 30): Collection
    {
        return $site->wooCommerceStats()
            ->where('date', '>=', now()->subDays($days)->toDateString())
            ->orderBy('date')
            ->get();
    }

    public function getTodayStats(Site $site): ?WooCommerceStat
    {
        return $site->wooCommerceStats()
            ->where('date', now()->toDateString())
            ->first();
    }
}
