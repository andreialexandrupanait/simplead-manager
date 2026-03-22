<?php

namespace Database\Factories;

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\ReportSchedule;
use App\Models\ReportTemplate;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $periodStart = fake()->dateTimeBetween('-60 days', '-30 days');
        $periodEnd = (clone $periodStart)->modify('+30 days');

        return [
            'site_id' => Site::factory(),
            'report_template_id' => ReportTemplate::factory(),
            'report_schedule_id' => null,
            'title' => fake()->randomElement(['Monthly Maintenance Report', 'Weekly Summary', 'Performance Report']).' - '.fake()->monthName().' '.fake()->year(),
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'file_path' => 'reports/'.fake()->uuid().'.pdf',
            'file_name' => 'report-'.fake()->date('Y-m').'.pdf',
            'file_size' => fake()->numberBetween(100_000, 5_000_000),
            'page_count' => fake()->numberBetween(3, 20),
            'status' => ReportStatus::Completed,
            'error_message' => null,
            'trigger' => fake()->randomElement(['scheduled', 'manual']),
            'was_sent' => false,
            'sent_at' => null,
            'sent_to' => null,
            'data_snapshot' => null,
            'generated_at' => fake()->dateTimeBetween('-7 days', 'now'),
        ];
    }

    /**
     * Indicate the report is completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReportStatus::Completed,
            'error_message' => null,
            'generated_at' => fake()->dateTimeBetween('-7 days', 'now'),
        ]);
    }

    /**
     * Indicate the report failed.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReportStatus::Failed,
            'error_message' => fake()->randomElement([
                'PDF generation timed out',
                'Failed to fetch site data',
                'Template rendering error',
                'Gotenberg service unavailable',
            ]),
            'file_path' => null,
            'file_name' => null,
            'file_size' => null,
            'page_count' => null,
            'generated_at' => null,
        ]);
    }

    /**
     * Indicate the report is pending.
     */
    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReportStatus::Pending,
            'file_path' => null,
            'file_name' => null,
            'file_size' => null,
            'page_count' => null,
            'generated_at' => null,
        ]);
    }

    /**
     * Indicate the report is currently generating.
     */
    public function generating(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReportStatus::Generating,
            'file_path' => null,
            'file_name' => null,
            'file_size' => null,
            'page_count' => null,
            'generated_at' => null,
        ]);
    }

    /**
     * Indicate the report was sent.
     */
    public function sent(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReportStatus::Completed,
            'was_sent' => true,
            'sent_at' => fake()->dateTimeBetween('-7 days', 'now'),
            'sent_to' => [fake()->safeEmail(), fake()->safeEmail()],
        ]);
    }

    /**
     * Indicate the report was generated from a schedule.
     */
    public function scheduled(): static
    {
        return $this->state(fn (array $attributes) => [
            'report_schedule_id' => ReportSchedule::factory(),
            'trigger' => 'scheduled',
        ]);
    }
}
