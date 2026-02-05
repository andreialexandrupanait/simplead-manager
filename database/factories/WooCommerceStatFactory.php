<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\WooCommerceStat;
use Illuminate\Database\Eloquent\Factories\Factory;

class WooCommerceStatFactory extends Factory
{
    protected $model = WooCommerceStat::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'date' => fake()->dateTimeBetween('-30 days', 'now'),
            'orders_count' => fake()->numberBetween(0, 50),
            'revenue' => fake()->randomFloat(2, 0, 5000),
            'currency' => 'USD',
            'average_order_value' => fake()->randomFloat(2, 20, 200),
            'products_sold_count' => fake()->numberBetween(0, 100),
            'refunds_count' => fake()->numberBetween(0, 5),
            'refunds_amount' => fake()->randomFloat(2, 0, 200),
            'new_customers' => fake()->numberBetween(0, 20),
            'returning_customers' => fake()->numberBetween(0, 30),
        ];
    }
}
