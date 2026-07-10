<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Site;
use App\Models\SiteRedirect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SiteRedirect>
 */
class SiteRedirectFactory extends Factory
{
    protected $model = SiteRedirect::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'source_path' => '/'.fake()->unique()->slug(),
            'target_url' => fake()->url(),
            'status_code' => 301,
            'is_active' => true,
        ];
    }
}
