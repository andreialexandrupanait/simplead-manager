<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\IncidentTriggerType;
use App\Exceptions\IncidentSkippedException;
use App\Jobs\RunIncidentResponse;
use App\Models\Site;
use App\Services\IncidentResponse\IncidentResponderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * P3-32: a guardrail SKIP (cooldown / hourly limit / disabled) is normal control
 * flow, not a failure. The SiteWentDown path trips it routinely, so it must NOT
 * spam the error log — it logs at debug. A genuine failure still logs error.
 */
class RunIncidentResponseSkipLoggingTest extends TestCase
{
    use RefreshDatabase;

    private function job(Site $site): RunIncidentResponse
    {
        return new RunIncidentResponse($site, IncidentTriggerType::SiteDown, 'uptime_monitor');
    }

    public function test_guardrail_skip_does_not_log_error(): void
    {
        $site = Site::factory()->create();

        $service = Mockery::mock(IncidentResponderService::class);
        $service->shouldReceive('respond')
            ->once()
            ->andThrow(new IncidentSkippedException('Cooldown active'));

        Log::spy();

        $this->job($site)->handle($service);

        Log::shouldNotHaveReceived('error');
        Log::shouldHaveReceived('debug')->once();
    }

    public function test_genuine_failure_still_logs_error(): void
    {
        $site = Site::factory()->create();

        $service = Mockery::mock(IncidentResponderService::class);
        $service->shouldReceive('respond')
            ->once()
            ->andThrow(new \RuntimeException('database exploded'));

        Log::spy();

        $this->job($site)->handle($service);

        Log::shouldHaveReceived('error')->once();
    }
}
