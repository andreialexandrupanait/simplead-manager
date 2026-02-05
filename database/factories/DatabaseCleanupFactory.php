<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\DatabaseCleanup;
use Illuminate\Database\Eloquent\Factories\Factory;

class DatabaseCleanupFactory extends Factory
{
    protected $model = DatabaseCleanup::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'revisions_deleted' => fake()->numberBetween(0, 500),
            'auto_drafts_deleted' => fake()->numberBetween(0, 50),
            'trash_posts_deleted' => fake()->numberBetween(0, 30),
            'spam_comments_deleted' => fake()->numberBetween(0, 200),
            'trash_comments_deleted' => fake()->numberBetween(0, 100),
            'transients_deleted' => fake()->numberBetween(0, 150),
            'orphaned_meta_deleted' => fake()->numberBetween(0, 80),
            'space_saved' => fake()->numberBetween(1000, 50000000),
            'status' => 'completed',
            'cleaned_at' => now()->subHours(fake()->numberBetween(1, 72)),
        ];
    }
}
