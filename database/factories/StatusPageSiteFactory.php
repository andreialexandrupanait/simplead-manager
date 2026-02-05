<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\StatusPage;
use App\Models\StatusPageSite;
use Illuminate\Database\Eloquent\Factories\Factory;

class StatusPageSiteFactory extends Factory
{
    protected $model = StatusPageSite::class;

    public function definition(): array
    {
        return [
            'status_page_id' => StatusPage::factory(),
            'site_id' => Site::factory(),
            'display_name' => fake()->optional()->company(),
            'sort_order' => fake()->numberBetween(0, 10),
            'is_visible' => true,
        ];
    }
}
