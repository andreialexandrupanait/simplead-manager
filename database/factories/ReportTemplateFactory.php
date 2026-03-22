<?php

namespace Database\Factories;

use App\Models\ReportTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ReportTemplate>
 */
class ReportTemplateFactory extends Factory
{
    protected $model = ReportTemplate::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Monthly Maintenance', 'Weekly Overview', 'Performance Report', 'Security Audit']).' Template',
            'description' => fake()->optional()->sentence(),
            'sections' => ['uptime', 'backups', 'security', 'performance', 'updates'],
            'section_overrides' => null,
            'section_options' => null,
            'company_name' => fake()->optional()->company(),
            'company_logo_path' => null,
            'company_website' => fake()->optional()->url(),
            'primary_color' => fake()->hexColor(),
            'intro_text' => fake()->optional()->paragraph(),
            'closing_text' => fake()->optional()->paragraph(),
            'is_default' => false,
        ];
    }

    /**
     * Indicate this is the default template.
     */
    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }

    /**
     * Indicate a fully branded template.
     */
    public function branded(): static
    {
        return $this->state(fn (array $attributes) => [
            'company_name' => fake()->company(),
            'company_website' => fake()->url(),
            'primary_color' => '#2563EB',
            'intro_text' => 'Here is your monthly website maintenance report.',
            'closing_text' => 'Thank you for trusting us with your website management.',
        ]);
    }
}
