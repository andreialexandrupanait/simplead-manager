<?php

namespace Database\Factories;

use App\Models\PerformanceTest;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PerformanceTest>
 */
class PerformanceTestFactory extends Factory
{
    protected $model = PerformanceTest::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'performance_monitor_id' => null,
            'performance_page_id' => null,
            'device' => fake()->randomElement(['mobile', 'desktop']),
            'url' => fake()->url(),
            'performance_score' => fake()->numberBetween(40, 100),
            'accessibility_score' => fake()->numberBetween(60, 100),
            'best_practices_score' => fake()->numberBetween(60, 100),
            'fcp' => fake()->randomFloat(1, 0.5, 5.0),
            'lcp' => fake()->randomFloat(1, 1.0, 8.0),
            'cls' => fake()->randomFloat(3, 0.0, 0.5),
            'tbt' => fake()->numberBetween(0, 1500),
            'si' => fake()->randomFloat(1, 1.0, 8.0),
            'tti' => fake()->randomFloat(1, 1.0, 10.0),
            'field_fcp' => fake()->optional(0.5)->randomFloat(1, 0.5, 5.0),
            'field_lcp' => fake()->optional(0.5)->randomFloat(1, 1.0, 8.0),
            'field_cls' => fake()->optional(0.5)->randomFloat(3, 0.0, 0.5),
            'field_inp' => fake()->optional(0.5)->numberBetween(50, 600),
            'field_ttfb' => fake()->optional(0.5)->numberBetween(100, 2000),
            'total_requests' => fake()->numberBetween(20, 150),
            'total_size_bytes' => fake()->numberBetween(500_000, 10_000_000),
            'html_size' => fake()->numberBetween(10_000, 200_000),
            'css_size' => fake()->numberBetween(50_000, 500_000),
            'js_size' => fake()->numberBetween(100_000, 3_000_000),
            'image_size' => fake()->numberBetween(100_000, 5_000_000),
            'font_size' => fake()->numberBetween(0, 500_000),
            'opportunities' => [],
            'diagnostics' => [],
            'third_party_scripts' => [],
            'dom_elements' => fake()->numberBetween(300, 3000),
            'dom_max_depth' => fake()->numberBetween(5, 30),
            'dom_max_children' => fake()->numberBetween(10, 200),
            'unused_js_bytes' => fake()->optional()->numberBetween(0, 500_000),
            'unused_css_bytes' => fake()->optional()->numberBetween(0, 200_000),
            'unused_js_details' => null,
            'unused_css_details' => null,
            'image_audit' => null,
            'wp_health_checks' => null,
            'screenshot_final' => null,
            'filmstrip' => null,
            'status' => 'completed',
            'error_message' => null,
            'lighthouse_version' => '12.0.0',
            'tested_at' => fake()->dateTimeBetween('-7 days', 'now'),
        ];
    }

    /**
     * Indicate a good performance result (score >= 90).
     */
    public function good(): static
    {
        return $this->state(fn (array $attributes) => [
            'performance_score' => fake()->numberBetween(90, 100),
            'fcp' => fake()->randomFloat(1, 0.5, 1.8),
            'lcp' => fake()->randomFloat(1, 1.0, 2.5),
            'cls' => fake()->randomFloat(3, 0.0, 0.1),
            'tbt' => fake()->numberBetween(0, 200),
            'si' => fake()->randomFloat(1, 1.0, 3.4),
            'tti' => fake()->randomFloat(1, 1.0, 3.8),
            'status' => 'completed',
        ]);
    }

    /**
     * Indicate a poor performance result (score < 50).
     */
    public function poor(): static
    {
        return $this->state(fn (array $attributes) => [
            'performance_score' => fake()->numberBetween(0, 49),
            'fcp' => fake()->randomFloat(1, 3.0, 8.0),
            'lcp' => fake()->randomFloat(1, 4.0, 15.0),
            'cls' => fake()->randomFloat(3, 0.25, 1.0),
            'tbt' => fake()->numberBetween(600, 5000),
            'si' => fake()->randomFloat(1, 5.8, 15.0),
            'tti' => fake()->randomFloat(1, 7.3, 20.0),
            'status' => 'completed',
        ]);
    }

    /**
     * Indicate a mobile test.
     */
    public function mobile(): static
    {
        return $this->state(fn (array $attributes) => [
            'device' => 'mobile',
        ]);
    }

    /**
     * Indicate a desktop test.
     */
    public function desktop(): static
    {
        return $this->state(fn (array $attributes) => [
            'device' => 'desktop',
        ]);
    }

    /**
     * Indicate the test failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'failed',
            'performance_score' => null,
            'accessibility_score' => null,
            'best_practices_score' => null,
            'fcp' => null,
            'lcp' => null,
            'cls' => null,
            'tbt' => null,
            'si' => null,
            'tti' => null,
            'error_message' => fake()->randomElement([
                'Lighthouse timed out after 60 seconds',
                'Page returned HTTP 500',
                'DNS resolution failed',
                'Page load timed out',
            ]),
        ]);
    }
}
