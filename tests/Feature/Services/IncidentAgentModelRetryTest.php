<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Enums\IncidentResponseStatus;
use App\Enums\IncidentTriggerType;
use App\Livewire\Settings\AiIncidentResponseSettings;
use App\Models\IncidentResponse;
use App\Models\Site;
use App\Models\User;
use App\Services\IncidentResponse\AiAgentService;
use App\Services\IncidentResponse\IncidentActionExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * P1-47: default Claude model must be a current (non-retired) id that passes the
 * settings validation, and the API call must retry transient 429/5xx instead of
 * silently killing the AI tier on the first blip.
 */
class IncidentAgentModelRetryTest extends TestCase
{
    use RefreshDatabase;

    public function test_default_model_is_in_the_allowlist(): void
    {
        $default = config('incident-response.ai.model');
        $allowed = config('incident-response.ai.allowed_models');

        $this->assertContains($default, $allowed);
        $this->assertStringStartsWith('claude-', $default);
    }

    public function test_default_model_passes_settings_validation(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(AiIncidentResponseSettings::class)
            ->set('model', config('incident-response.ai.model'))
            ->call('save')
            ->assertHasNoErrors('model');
    }

    public function test_retired_model_is_rejected_by_settings_validation(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(AiIncidentResponseSettings::class)
            ->set('model', 'claude-sonnet-4-20250514')
            ->call('save')
            ->assertHasErrors('model');
    }

    public function test_transient_429_is_retried_then_succeeds(): void
    {
        config(['incident-response.ai.api_key' => 'test-key']);
        config(['incident-response.ai.retry_base_delay_ms' => 0]);

        $site = Site::factory()->create();
        $response = IncidentResponse::factory()->create([
            'site_id' => $site->id,
            'status' => IncidentResponseStatus::Executing,
            'trigger_type' => IncidentTriggerType::SiteDown,
        ]);
        $executor = $this->createMock(IncidentActionExecutor::class);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['type' => 'error', 'error' => ['type' => 'rate_limit_error']], 429)
                ->push([
                    'stop_reason' => 'tool_use',
                    'content' => [[
                        'type' => 'tool_use',
                        'id' => 'call_1',
                        'name' => 'resolve_incident',
                        'input' => ['summary' => 'Recovered after retry'],
                    ]],
                    'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
                ], 200),
        ]);

        $result = (new AiAgentService)->diagnoseAndFix($response, $site, $executor, [
            'site' => ['name' => $site->name, 'url' => $site->url],
        ]);

        $this->assertTrue($result);
        $this->assertSame(IncidentResponseStatus::Resolved, $response->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_persistent_500_degrades_gracefully_without_throwing(): void
    {
        config(['incident-response.ai.api_key' => 'test-key']);
        config(['incident-response.ai.retry_base_delay_ms' => 0]);
        config(['incident-response.ai.max_attempts' => 3]);

        $site = Site::factory()->create();
        $response = IncidentResponse::factory()->create([
            'site_id' => $site->id,
            'status' => IncidentResponseStatus::Executing,
            'trigger_type' => IncidentTriggerType::SiteDown,
        ]);
        $executor = $this->createMock(IncidentActionExecutor::class);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['type' => 'error'], 500),
        ]);

        $result = (new AiAgentService)->diagnoseAndFix($response, $site, $executor, [
            'site' => ['name' => $site->name, 'url' => $site->url],
        ]);

        $this->assertFalse($result);
        // Still non-terminal — the caller (responder) escalates; no uncaught throw.
        $this->assertFalse($response->fresh()->status->isTerminal());
        Http::assertSentCount(3);
    }
}
