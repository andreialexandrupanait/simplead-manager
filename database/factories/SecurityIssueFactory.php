<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\SecurityIssue;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SecurityIssue>
 */
class SecurityIssueFactory extends Factory
{
    protected $model = SecurityIssue::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'category' => 'hardening',
            // unique per (site_id, type) constraint
            'type' => fake()->unique()->lexify('issue_type_????'),
            'severity' => 'critical',
            'title' => fake()->sentence(4),
            'description' => fake()->sentence(),
            'recommendation' => fake()->sentence(),
            'is_fixed' => false,
            'is_ignored' => false,
            'first_detected_at' => now(),
        ];
    }
}
