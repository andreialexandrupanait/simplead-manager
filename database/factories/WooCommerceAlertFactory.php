<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\WooCommerceAlert;
use Illuminate\Database\Eloquent\Factories\Factory;

class WooCommerceAlertFactory extends Factory
{
    protected $model = WooCommerceAlert::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'type' => fake()->randomElement(['low_stock', 'out_of_stock', 'failed_order', 'high_refunds']),
            'product_id' => fake()->numberBetween(1, 500),
            'product_name' => fake()->words(3, true),
            'message' => fake()->sentence(),
            'is_acknowledged' => false,
        ];
    }
}
