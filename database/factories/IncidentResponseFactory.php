<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\IncidentResponseStatus;
use App\Enums\IncidentTriggerType;
use App\Models\IncidentResponse;
use App\Models\Site;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\IncidentResponse>
 */
class IncidentResponseFactory extends Factory
{
    protected $model = IncidentResponse::class;

    public function definition(): array
    {
        return [
            'site_id' => Site::factory(),
            'trigger_type' => fake()->randomElement(IncidentTriggerType::cases()),
            'trigger_source' => fake()->randomElement(['uptime_monitor', 'security_scan', 'cron']),
            'trigger_source_id' => null,
            'status' => IncidentResponseStatus::Pending,
            'resolution_method' => null,
            'playbook_name' => null,
            'diagnosis' => null,
            'actions_taken' => null,
            'ai_context' => null,
            'summary' => null,
            'actions_count' => 0,
            'ai_calls_count' => 0,
            'total_tokens_used' => 0,
            'backup_created' => false,
            'backup_id' => null,
            'resolved_at' => null,
            'escalated_at' => null,
            'response_attempted_at' => now(),
            'acknowledged_at' => null,
        ];
    }

    public function resolved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IncidentResponseStatus::Resolved,
            'resolution_method' => 'playbook',
            'summary' => 'Issue resolved automatically.',
            'resolved_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IncidentResponseStatus::Failed,
            'summary' => 'Could not resolve the issue.',
        ]);
    }

    public function escalated(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IncidentResponseStatus::Escalated,
            'summary' => 'Requires human intervention.',
            'escalated_at' => now(),
        ]);
    }

    public function diagnosing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IncidentResponseStatus::Diagnosing,
        ]);
    }

    public function executing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => IncidentResponseStatus::Executing,
        ]);
    }

    public function atActionLimit(): static
    {
        return $this->state(fn (array $attributes) => [
            'actions_count' => config('incident-response.safety.max_actions_per_incident', 10),
        ]);
    }

    public function atAiCallLimit(): static
    {
        return $this->state(fn (array $attributes) => [
            'ai_calls_count' => config('incident-response.safety.max_ai_calls_per_incident', 5),
        ]);
    }

    public function siteDown(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => IncidentTriggerType::SiteDown,
            'trigger_source' => 'uptime_monitor',
        ]);
    }

    public function vulnerability(): static
    {
        return $this->state(fn (array $attributes) => [
            'trigger_type' => IncidentTriggerType::Vulnerability,
            'trigger_source' => 'security_scan',
        ]);
    }
}
