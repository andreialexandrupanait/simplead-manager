<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\IncidentResponseStatus;
use App\Enums\IncidentTriggerType;
use App\Models\IncidentResponse;
use App\Models\Site;
use App\Services\IncidentResponse\AiAgentService;
use App\Services\IncidentResponse\ContextGatherer;
use App\Services\IncidentResponse\IncidentActionExecutor;
use App\Services\IncidentResponse\IncidentResponderService;
use App\Services\IncidentResponse\PlaybookRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncidentResponderServiceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create();
    }

    private function makeService(
        ?PlaybookRunner $playbookRunner = null,
        ?AiAgentService $aiAgent = null,
    ): IncidentResponderService {
        $executor = $this->createMock(IncidentActionExecutor::class);
        $playbookRunner ??= $this->createMock(PlaybookRunner::class);
        $contextGatherer = $this->createMock(ContextGatherer::class);
        $contextGatherer->method('gather')->willReturn(['site' => ['name' => 'test']]);
        $aiAgent ??= $this->createMock(AiAgentService::class);

        return new IncidentResponderService($executor, $playbookRunner, $contextGatherer, $aiAgent);
    }

    public function test_disabled_config_throws(): void
    {
        config(['incident-response.enabled' => false]);

        $service = $this->makeService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('disabled');

        $service->respond($this->site, IncidentTriggerType::SiteDown, 'uptime_monitor');
    }

    public function test_cooldown_guard_prevents_duplicate(): void
    {
        config(['incident-response.enabled' => true]);
        config(['incident-response.safety.cooldown_minutes' => 30]);

        // Create a recent incident
        IncidentResponse::factory()->create([
            'site_id' => $this->site->id,
            'trigger_type' => IncidentTriggerType::SiteDown,
            'created_at' => now()->subMinutes(10),
        ]);

        $service = $this->makeService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cooldown active');

        $service->respond($this->site, IncidentTriggerType::SiteDown, 'uptime_monitor');
    }

    public function test_hourly_limit_exceeded(): void
    {
        config(['incident-response.enabled' => true]);
        config(['incident-response.safety.cooldown_minutes' => 0]);
        config(['incident-response.safety.max_incidents_per_site_per_hour' => 2]);

        IncidentResponse::factory()->count(2)->create([
            'site_id' => $this->site->id,
            'trigger_type' => IncidentTriggerType::SecurityCritical,
            'created_at' => now()->subMinutes(20),
        ]);

        $service = $this->makeService();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Hourly limit');

        $service->respond($this->site, IncidentTriggerType::SiteDown, 'uptime_monitor');
    }

    public function test_playbook_resolves_incident(): void
    {
        config(['incident-response.enabled' => true]);
        config(['incident-response.routing.playbook_first' => true]);

        $playbookRunner = $this->createMock(PlaybookRunner::class);
        $playbookRunner->method('run')->willReturn(true);

        $service = $this->makeService(playbookRunner: $playbookRunner);

        $response = $service->respond($this->site, IncidentTriggerType::SiteDown, 'uptime_monitor');

        $this->assertSame(IncidentResponseStatus::Resolved, $response->status);
        $this->assertSame('playbook', $response->resolution_method);
    }

    public function test_escalation_when_both_tiers_fail(): void
    {
        config(['incident-response.enabled' => true]);
        config(['incident-response.routing.playbook_first' => true]);
        config(['incident-response.routing.ai_fallback' => true]);
        config(['incident-response.ai.api_key' => 'test-key']);

        $playbookRunner = $this->createMock(PlaybookRunner::class);
        $playbookRunner->method('run')->willReturn(false);

        $aiAgent = $this->createMock(AiAgentService::class);
        $aiAgent->method('diagnoseAndFix')->willReturn(false);

        $service = $this->makeService(playbookRunner: $playbookRunner, aiAgent: $aiAgent);

        $response = $service->respond($this->site, IncidentTriggerType::SiteDown, 'uptime_monitor');

        $this->assertSame(IncidentResponseStatus::Escalated, $response->status);
    }

    public function test_creates_incident_response_record(): void
    {
        config(['incident-response.enabled' => true]);

        $playbookRunner = $this->createMock(PlaybookRunner::class);
        $playbookRunner->method('run')->willReturn(true);

        $service = $this->makeService(playbookRunner: $playbookRunner);

        $response = $service->respond($this->site, IncidentTriggerType::Vulnerability, 'security_scan', 42);

        $this->assertDatabaseHas('incident_responses', [
            'site_id' => $this->site->id,
            'trigger_type' => IncidentTriggerType::Vulnerability->value,
            'trigger_source' => 'security_scan',
            'trigger_source_id' => 42,
        ]);
    }

    public function test_different_trigger_types_not_blocked_by_cooldown(): void
    {
        config(['incident-response.enabled' => true]);
        config(['incident-response.safety.cooldown_minutes' => 30]);

        IncidentResponse::factory()->create([
            'site_id' => $this->site->id,
            'trigger_type' => IncidentTriggerType::SiteDown,
            'created_at' => now()->subMinutes(10),
        ]);

        $playbookRunner = $this->createMock(PlaybookRunner::class);
        $playbookRunner->method('run')->willReturn(true);

        $service = $this->makeService(playbookRunner: $playbookRunner);

        // Different trigger type should not be blocked
        $response = $service->respond($this->site, IncidentTriggerType::Vulnerability, 'security_scan');

        $this->assertSame(IncidentResponseStatus::Resolved, $response->status);
    }
}
