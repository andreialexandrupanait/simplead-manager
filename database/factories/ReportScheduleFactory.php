<?php

namespace Database\Factories;

use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ReportSchedule>
 */
class ReportScheduleFactory extends Factory
{
    protected $model = ReportSchedule::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'report_template_id' => ReportTemplate::factory(),
            'is_active' => true,
            'frequency' => fake()->randomElement(['weekly', 'monthly']),
            'day_of_week' => fake()->numberBetween(0, 6),
            'day_of_month' => fake()->numberBetween(1, 28),
            'time' => fake()->time('H:i'),
            'timezone' => fake()->timezone(),
            'period' => fake()->randomElement(['weekly', 'monthly']),
            'recipient_emails' => fn () => [fake()->safeEmail(), fake()->safeEmail()],
            'send_copy_to_admin' => fake()->boolean(70),
            'email_subject' => fake()->optional()->sentence(4),
            'email_body' => fake()->optional()->paragraph(),
            'client_name' => fake()->optional()->company(),
            'client_logo_path' => null,
            'last_generated_at' => fake()->optional()->dateTimeBetween('-30 days', '-1 day'),
            'last_sent_at' => fake()->optional()->dateTimeBetween('-30 days', '-1 day'),
            'next_run_at' => fake()->dateTimeBetween('now', '+30 days'),
            'reminder_sent_at' => null,
        ];
    }

    /**
     * Indicate the schedule is active.
     */
    public function active(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => true,
        ]);
    }

    /**
     * Indicate the schedule is inactive.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate a weekly schedule.
     */
    public function weekly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'weekly',
            'period' => 'weekly',
            'day_of_week' => 1, // Monday
            'time' => '08:00',
        ]);
    }

    /**
     * Indicate a monthly schedule.
     */
    public function monthly(): static
    {
        return $this->state(fn (array $attributes) => [
            'frequency' => 'monthly',
            'period' => 'monthly',
            'day_of_month' => 1,
            'time' => '08:00',
        ]);
    }
}
