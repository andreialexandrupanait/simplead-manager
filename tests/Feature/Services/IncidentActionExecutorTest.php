<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\IncidentResponseStatus;
use App\Models\IncidentResponse;
use App\Models\IncidentResponseAction;
use App\Models\Site;
use App\Services\DatabaseCleanupService;
use App\Services\IncidentResponse\IncidentActionExecutor;
use App\Services\PluginManagerService;
use App\Services\SafeUpdateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class IncidentActionExecutorTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private IncidentResponse $response;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create();
        $this->response = IncidentResponse::factory()->create([
            'site_id' => $this->site->id,
            'status' => IncidentResponseStatus::Executing,
        ]);
    }

    private function makeExecutor($api = null): IncidentActionExecutor
    {
        $pluginManager = $this->createMock(PluginManagerService::class);
        $safeUpdateService = $this->createMock(SafeUpdateService::class);
        $dbCleanupService = $this->createMock(DatabaseCleanupService::class);

        return new IncidentActionExecutor(
            $this->createMockApiFactory($api),
            $pluginManager,
            $safeUpdateService,
            $dbCleanupService,
        );
    }

    public function test_action_limit_guard(): void
    {
        $response = IncidentResponse::factory()->atActionLimit()->create([
            'site_id' => $this->site->id,
        ]);

        $executor = $this->makeExecutor();
        $result = $executor->execute($response, $this->site, 'run_diagnostic', 'playbook');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Action limit reached', $result['error']);
    }

    public function test_unknown_action_type_returns_error(): void
    {
        $executor = $this->makeExecutor();
        $result = $executor->execute($this->response, $this->site, 'destroy_everything', 'playbook');

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Unknown action', $result['error']);
    }

    public function test_run_diagnostic_calls_api(): void
    {
        $api = $this->createMockApi();
        $api->expects($this->once())->method('runDiagnostic')->willReturn([
            'loopback' => ['status' => 200],
            'debug_log' => [],
        ]);

        $executor = $this->makeExecutor($api);
        $result = $executor->execute($this->response, $this->site, 'run_diagnostic', 'playbook');

        $this->assertArrayHasKey('loopback', $result);
    }

    public function test_flush_cache_calls_api(): void
    {
        $api = $this->createMockApi();
        $api->expects($this->once())->method('clearCache')->willReturn(['success' => true]);

        $executor = $this->makeExecutor($api);
        $result = $executor->execute($this->response, $this->site, 'flush_cache', 'ai_agent');

        $this->assertTrue($result['success']);
    }

    public function test_action_records_incident_response_action(): void
    {
        $api = $this->createMockApi();
        $api->method('healthCheck')->willReturn(['status' => 'ok', 'checks' => []]);

        $executor = $this->makeExecutor($api);
        $executor->execute($this->response, $this->site, 'health_check', 'playbook');

        $this->assertDatabaseHas('incident_response_actions', [
            'incident_response_id' => $this->response->id,
            'action_type' => 'health_check',
            'tier' => 'playbook',
            'status' => 'success',
        ]);
    }

    public function test_action_increments_actions_count(): void
    {
        $api = $this->createMockApi();
        $api->method('clearCache')->willReturn(['success' => true]);

        $executor = $this->makeExecutor($api);
        $executor->execute($this->response, $this->site, 'flush_cache', 'playbook');

        $this->response->refresh();
        $this->assertSame(1, $this->response->actions_count);
    }

    public function test_exception_recorded_as_failed_action(): void
    {
        $api = $this->createMockApi();
        $api->method('runDiagnostic')->willThrowException(new \RuntimeException('Connection refused'));

        $executor = $this->makeExecutor($api);
        $result = $executor->execute($this->response, $this->site, 'run_diagnostic', 'playbook');

        $this->assertFalse($result['success']);
        $this->assertDatabaseHas('incident_response_actions', [
            'incident_response_id' => $this->response->id,
            'status' => 'failed',
            'action_type' => 'run_diagnostic',
        ]);
    }

    public function test_sequence_increments_across_calls(): void
    {
        $api = $this->createMockApi();
        $api->method('clearCache')->willReturn(['success' => true]);
        $api->method('healthCheck')->willReturn(['status' => 'ok', 'checks' => []]);

        $executor = $this->makeExecutor($api);
        $executor->execute($this->response, $this->site, 'flush_cache', 'playbook');
        $executor->execute($this->response, $this->site, 'health_check', 'playbook');

        $actions = IncidentResponseAction::where('incident_response_id', $this->response->id)
            ->orderBy('sequence')
            ->get();

        $this->assertSame(0, $actions[0]->sequence);
        $this->assertSame(1, $actions[1]->sequence);
    }

    public function test_check_site_up_uses_http(): void
    {
        Http::fake([
            $this->site->url => Http::response('OK', 200),
        ]);

        $executor = $this->makeExecutor();
        $result = $executor->execute($this->response, $this->site, 'check_site_up', 'playbook');

        $this->assertTrue($result['is_up']);
        $this->assertSame(200, $result['status_code']);
    }

    public function test_deactivate_plugin_missing_id_returns_error(): void
    {
        // deactivate_plugin is destructive (P0-20): give the incident a backup so
        // the invariant is satisfied and we reach the plugin_id validation.
        $this->response->update(['backup_created' => true]);

        $executor = $this->makeExecutor();
        $result = $executor->execute($this->response, $this->site, 'deactivate_plugin', 'ai_agent', []);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Missing plugin_id', $result['error']);
    }
}
