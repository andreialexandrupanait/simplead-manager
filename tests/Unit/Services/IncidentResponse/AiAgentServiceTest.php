<?php

declare(strict_types=1);

namespace Tests\Unit\Services\IncidentResponse;

use App\Enums\IncidentResponseStatus;
use App\Enums\IncidentTriggerType;
use App\Models\IncidentResponse;
use App\Models\Site;
use App\Services\IncidentResponse\AiAgentService;
use App\Services\IncidentResponse\IncidentActionExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiAgentServiceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create();
    }

    public function test_returns_false_when_no_api_key(): void
    {
        config(['incident-response.ai.api_key' => null]);

        $response = IncidentResponse::factory()->create([
            'site_id' => $this->site->id,
            'status' => IncidentResponseStatus::Executing,
        ]);
        $executor = $this->createMock(IncidentActionExecutor::class);

        $service = new AiAgentService;
        $result = $service->diagnoseAndFix($response, $this->site, $executor, []);

        $this->assertFalse($result);
    }

    public function test_respects_ai_call_limit(): void
    {
        config(['incident-response.ai.api_key' => 'test-key']);

        $response = IncidentResponse::factory()->atAiCallLimit()->create([
            'site_id' => $this->site->id,
            'status' => IncidentResponseStatus::Executing,
        ]);
        $executor = $this->createMock(IncidentActionExecutor::class);

        Http::fake(); // Should never be called

        $service = new AiAgentService;
        $result = $service->diagnoseAndFix($response, $this->site, $executor, []);

        $this->assertFalse($result);
        Http::assertNothingSent();
    }

    public function test_handles_api_failure_gracefully(): void
    {
        config(['incident-response.ai.api_key' => 'test-key']);
        config(['incident-response.ai.retry_base_delay_ms' => 0]);

        $response = IncidentResponse::factory()->create([
            'site_id' => $this->site->id,
            'status' => IncidentResponseStatus::Executing,
        ]);
        $executor = $this->createMock(IncidentActionExecutor::class);

        Http::fake([
            'api.anthropic.com/*' => Http::response(null, 500),
        ]);

        $service = new AiAgentService;
        $result = $service->diagnoseAndFix($response, $this->site, $executor, [
            'site' => ['name' => $this->site->name, 'url' => $this->site->url],
        ]);

        $this->assertFalse($result);
    }

    public function test_end_turn_stops_loop(): void
    {
        config(['incident-response.ai.api_key' => 'test-key']);

        $response = IncidentResponse::factory()->create([
            'site_id' => $this->site->id,
            'status' => IncidentResponseStatus::Executing,
            'trigger_type' => IncidentTriggerType::SiteDown,
        ]);
        $executor = $this->createMock(IncidentActionExecutor::class);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'end_turn',
                'content' => [['type' => 'text', 'text' => 'Cannot determine issue']],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ]),
        ]);

        $service = new AiAgentService;
        $result = $service->diagnoseAndFix($response, $this->site, $executor, [
            'site' => ['name' => $this->site->name, 'url' => $this->site->url],
        ]);

        $this->assertFalse($result);
        $response->refresh();
        $this->assertNotNull($response->ai_context);
        $this->assertSame(1, $response->ai_calls_count);
    }

    public function test_resolve_incident_tool_marks_resolved(): void
    {
        config(['incident-response.ai.api_key' => 'test-key']);

        $response = IncidentResponse::factory()->create([
            'site_id' => $this->site->id,
            'status' => IncidentResponseStatus::Executing,
            'trigger_type' => IncidentTriggerType::SiteDown,
        ]);
        $executor = $this->createMock(IncidentActionExecutor::class);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'tool_use',
                'content' => [
                    ['type' => 'tool_use', 'id' => 'call_1', 'name' => 'resolve_incident', 'input' => ['summary' => 'Fixed by flushing cache']],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ]),
        ]);

        $service = new AiAgentService;
        $result = $service->diagnoseAndFix($response, $this->site, $executor, [
            'site' => ['name' => $this->site->name, 'url' => $this->site->url],
        ]);

        $this->assertTrue($result);
        $response->refresh();
        $this->assertSame(IncidentResponseStatus::Resolved, $response->status);
        $this->assertSame('ai_agent', $response->resolution_method);
    }

    public function test_escalate_tool_marks_escalated(): void
    {
        config(['incident-response.ai.api_key' => 'test-key']);

        $response = IncidentResponse::factory()->create([
            'site_id' => $this->site->id,
            'status' => IncidentResponseStatus::Executing,
            'trigger_type' => IncidentTriggerType::SiteDown,
        ]);
        $executor = $this->createMock(IncidentActionExecutor::class);

        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'stop_reason' => 'tool_use',
                'content' => [
                    [
                        'type' => 'tool_use',
                        'id' => 'call_1',
                        'name' => 'escalate_to_human',
                        'input' => [
                            'reason' => 'Cannot determine root cause',
                            'diagnosis' => 'Site returns 500 but no errors in log',
                            'recommended_actions' => 'Check server error logs directly',
                        ],
                    ],
                ],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ]),
        ]);

        $service = new AiAgentService;
        $result = $service->diagnoseAndFix($response, $this->site, $executor, [
            'site' => ['name' => $this->site->name, 'url' => $this->site->url],
        ]);

        $this->assertTrue($result);
        $response->refresh();
        $this->assertSame(IncidentResponseStatus::Escalated, $response->status);
        $this->assertStringContainsString('Cannot determine root cause', $response->summary);
    }

    public function test_tool_definitions_do_not_include_delete_plugin(): void
    {
        $service = new AiAgentService;
        $reflection = new \ReflectionMethod($service, 'getToolDefinitions');
        $tools = $reflection->invoke($service);

        $toolNames = array_column($tools, 'name');
        $this->assertNotContains('delete_plugin', $toolNames);
        $this->assertNotContains('delete_theme', $toolNames);
    }
}
