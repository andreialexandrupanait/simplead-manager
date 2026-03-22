<?php

namespace Tests\Unit\Services;

use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\User;
use App\Services\CircuitBreakerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class CircuitBreakerServiceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->for(User::factory())->create();
    }

    #[Test]
    public function it_creates_health_state_on_first_interaction(): void
    {
        $this->assertDatabaseMissing('site_health_state', ['site_id' => $this->site->id]);

        CircuitBreakerService::recordSuccess($this->site);

        $this->assertDatabaseHas('site_health_state', [
            'site_id' => $this->site->id,
            'circuit_state' => 'closed',
        ]);
    }

    #[Test]
    public function it_stays_closed_after_fewer_failures_than_threshold(): void
    {
        CircuitBreakerService::recordFailure($this->site, 'error 1');
        CircuitBreakerService::recordFailure($this->site, 'error 2');

        $state = SiteHealthState::where('site_id', $this->site->id)->first();

        $this->assertEquals('closed', $state->circuit_state);
        $this->assertEquals(2, $state->consecutive_failures);
    }

    #[Test]
    public function it_opens_after_reaching_failure_threshold(): void
    {
        CircuitBreakerService::recordFailure($this->site, 'error 1');
        CircuitBreakerService::recordFailure($this->site, 'error 2');
        CircuitBreakerService::recordFailure($this->site, 'error 3');

        $state = SiteHealthState::where('site_id', $this->site->id)->first();

        $this->assertEquals('open', $state->circuit_state);
        $this->assertEquals(3, $state->consecutive_failures);
        $this->assertNotNull($state->circuit_opened_at);
        $this->assertEquals(1, $state->circuit_breaks_last_24h);
    }

    #[Test]
    public function success_resets_failure_count(): void
    {
        CircuitBreakerService::recordFailure($this->site, 'error 1');
        CircuitBreakerService::recordFailure($this->site, 'error 2');
        CircuitBreakerService::recordSuccess($this->site);

        $state = SiteHealthState::where('site_id', $this->site->id)->first();

        $this->assertEquals('closed', $state->circuit_state);
        $this->assertEquals(0, $state->consecutive_failures);
    }

    #[Test]
    public function half_open_transitions_to_closed_on_success(): void
    {
        SiteHealthState::create([
            'site_id' => $this->site->id,
            'circuit_state' => 'half_open',
            'consecutive_failures' => 3,
            'circuit_opened_at' => now()->subHours(2),
        ]);

        CircuitBreakerService::recordSuccess($this->site);

        $state = SiteHealthState::where('site_id', $this->site->id)->first();

        $this->assertEquals('closed', $state->circuit_state);
        $this->assertEquals(0, $state->consecutive_failures);
        $this->assertNull($state->circuit_opened_at);
    }

    #[Test]
    public function half_open_reopens_on_failure(): void
    {
        SiteHealthState::create([
            'site_id' => $this->site->id,
            'circuit_state' => 'half_open',
            'consecutive_failures' => 3,
            'circuit_opened_at' => now()->subHours(2),
            'circuit_breaks_last_24h' => 1,
        ]);

        CircuitBreakerService::recordFailure($this->site, 'still failing');

        $state = SiteHealthState::where('site_id', $this->site->id)->first();

        $this->assertEquals('open', $state->circuit_state);
        $this->assertEquals(4, $state->consecutive_failures);
        $this->assertEquals(2, $state->circuit_breaks_last_24h);
    }

    #[Test]
    public function monitoring_disabled_after_max_breaks_in_24h(): void
    {
        SiteHealthState::create([
            'site_id' => $this->site->id,
            'circuit_state' => 'half_open',
            'consecutive_failures' => 3,
            'circuit_opened_at' => now()->subHours(2),
            'circuit_breaks_last_24h' => 2,
            'circuit_breaks_reset_at' => now()->addHours(22),
        ]);

        CircuitBreakerService::recordFailure($this->site, 'third break');

        $state = SiteHealthState::where('site_id', $this->site->id)->first();

        $this->assertTrue($state->is_monitoring_disabled);
        $this->assertEquals(3, $state->circuit_breaks_last_24h);
    }

    #[Test]
    public function check_half_open_transitions_expired_open_circuits(): void
    {
        SiteHealthState::create([
            'site_id' => $this->site->id,
            'circuit_state' => 'open',
            'circuit_opened_at' => now()->subMinutes(61),
            'is_monitoring_disabled' => false,
        ]);

        CircuitBreakerService::checkHalfOpen();

        $state = SiteHealthState::where('site_id', $this->site->id)->first();

        $this->assertEquals('half_open', $state->circuit_state);
    }

    #[Test]
    public function check_half_open_ignores_recent_open_circuits(): void
    {
        SiteHealthState::create([
            'site_id' => $this->site->id,
            'circuit_state' => 'open',
            'circuit_opened_at' => now()->subMinutes(30),
            'is_monitoring_disabled' => false,
        ]);

        CircuitBreakerService::checkHalfOpen();

        $state = SiteHealthState::where('site_id', $this->site->id)->first();

        $this->assertEquals('open', $state->circuit_state);
    }

    #[Test]
    public function check_half_open_ignores_disabled_monitoring(): void
    {
        SiteHealthState::create([
            'site_id' => $this->site->id,
            'circuit_state' => 'open',
            'circuit_opened_at' => now()->subMinutes(61),
            'is_monitoring_disabled' => true,
        ]);

        CircuitBreakerService::checkHalfOpen();

        $state = SiteHealthState::where('site_id', $this->site->id)->first();

        $this->assertEquals('open', $state->circuit_state);
    }

    #[Test]
    public function re_enable_resets_all_state(): void
    {
        SiteHealthState::create([
            'site_id' => $this->site->id,
            'circuit_state' => 'open',
            'consecutive_failures' => 5,
            'circuit_opened_at' => now(),
            'circuit_breaks_last_24h' => 3,
            'is_monitoring_disabled' => true,
            'last_failure_at' => now(),
            'last_failure_reason' => 'something broke',
        ]);

        CircuitBreakerService::reEnable($this->site);

        $state = SiteHealthState::where('site_id', $this->site->id)->first();

        $this->assertEquals('closed', $state->circuit_state);
        $this->assertEquals(0, $state->consecutive_failures);
        $this->assertNull($state->circuit_opened_at);
        $this->assertEquals(0, $state->circuit_breaks_last_24h);
        $this->assertFalse($state->is_monitoring_disabled);
        $this->assertNull($state->last_failure_at);
    }
}
