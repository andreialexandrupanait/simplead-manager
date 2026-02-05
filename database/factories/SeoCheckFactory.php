<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\SeoCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

class SeoCheckFactory extends Factory
{
    protected $model = SeoCheck::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'homepage_title' => fake()->sentence(5),
            'homepage_meta_description' => fake()->sentence(15),
            'has_sitemap' => fake()->boolean(80),
            'sitemap_url' => '/sitemap.xml',
            'sitemap_pages_count' => fake()->numberBetween(10, 500),
            'has_robots_txt' => fake()->boolean(90),
            'robots_txt_issues' => [],
            'has_og_tags' => fake()->boolean(70),
            'has_twitter_cards' => fake()->boolean(50),
            'has_schema_markup' => fake()->boolean(40),
            'has_canonical' => fake()->boolean(80),
            'has_h1' => fake()->boolean(90),
            'heading_hierarchy_ok' => fake()->boolean(70),
            'indexability_issues' => [],
            'score' => fake()->numberBetween(40, 100),
            'checked_at' => now()->subHours(fake()->numberBetween(1, 48)),
        ];
    }
}
