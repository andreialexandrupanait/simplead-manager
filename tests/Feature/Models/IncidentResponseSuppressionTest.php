<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Enums\IncidentResponseStatus;
use App\Enums\IncidentTriggerType;
use App\Models\IncidentResponse;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P0-21 (audit IR-02): unresolved/escalated incidents used to re-enter the full
 * AI pipeline every cooldown window forever. These tests pin the suppression
 * rules the dispatcher now relies on.
 */
class IncidentResponseSuppressionTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake(); // swallow FetchSiteFavicon dispatched on Site creation
        config([
            'incident-response.safety.cooldown_minutes' => 10,
            'incident-response.safety.max_backoff_minutes' => 1440,
        ]);
        $this->site = Site::factory()->create();
    }

    private function incident(array $attributes): IncidentResponse
    {
        return IncidentResponse::factory()->create(array_merge([
            'site_id' => $this->site->id,
            'trigger_type' => IncidentTriggerType::Vulnerability,
        ], $attributes));
    }

    public function test_running_incident_suppresses_redispatch(): void
    {
        $incident = $this->incident([
            'status' => IncidentResponseStatus::Executing,
            'response_attempted_at' => now()->subHours(5),
        ]);

        $this->assertTrue($incident->suppressesRedispatch());
    }

    public function test_escalated_unacknowledged_incident_suppresses_indefinitely(): void
    {
        $incident = $this->incident([
            'status' => IncidentResponseStatus::Escalated,
            'escalated_at' => now()->subHours(5),
            'response_attempted_at' => now()->subHours(5), // well past cooldown
            'acknowledged_at' => null,
        ]);

        $this->assertTrue($incident->suppressesRedispatch());
    }

    public function test_acknowledged_escalated_incident_no_longer_suppresses(): void
    {
        $incident = $this->incident([
            'status' => IncidentResponseStatus::Escalated,
            'escalated_at' => now()->subHours(5),
            'response_attempted_at' => now()->subHours(5),
            'acknowledged_at' => now(),
        ]);

        $this->assertFalse($incident->suppressesRedispatch());
    }

    public function test_recent_resolved_incident_suppresses_within_cooldown(): void
    {
        $incident = $this->incident([
            'status' => IncidentResponseStatus::Resolved,
            'resolved_at' => now()->subMinutes(3),
            'response_attempted_at' => now()->subMinutes(3),
        ]);

        $this->assertTrue($incident->suppressesRedispatch());
    }

    public function test_old_resolved_incident_allows_redispatch(): void
    {
        $incident = $this->incident([
            'status' => IncidentResponseStatus::Resolved,
            'resolved_at' => now()->subHour(),
            'response_attempted_at' => now()->subHour(),
        ]);

        $this->assertFalse($incident->suppressesRedispatch());
    }

    public function test_single_failure_uses_base_cooldown_backoff(): void
    {
        // 1 failure -> backoff = cooldown (10 min). Attempt 20 min ago is past it.
        $incident = $this->incident([
            'status' => IncidentResponseStatus::Failed,
            'response_attempted_at' => now()->subMinutes(20),
        ]);

        $this->assertSame(10, $incident->failureBackoffMinutes());
        $this->assertFalse($incident->suppressesRedispatch());
    }

    public function test_repeated_failures_apply_exponential_backoff(): void
    {
        // 3 failures -> backoff = 10 * 2^2 = 40 min. Latest attempt 20 min ago is
        // past cooldown but still inside the backoff window -> suppressed.
        $this->incident([
            'status' => IncidentResponseStatus::Failed,
            'response_attempted_at' => now()->subHours(3),
        ]);
        $this->incident([
            'status' => IncidentResponseStatus::Failed,
            'response_attempted_at' => now()->subHours(2),
        ]);
        $latest = $this->incident([
            'status' => IncidentResponseStatus::Failed,
            'response_attempted_at' => now()->subMinutes(20),
        ]);

        $this->assertSame(40, $latest->failureBackoffMinutes());
        $this->assertTrue($latest->suppressesRedispatch());
    }

    public function test_backoff_is_capped_by_max_backoff_minutes(): void
    {
        config(['incident-response.safety.max_backoff_minutes' => 30]);

        // 3 failures would be 40 min uncapped; capped to 30.
        $this->incident(['status' => IncidentResponseStatus::Failed, 'response_attempted_at' => now()->subHours(3)]);
        $this->incident(['status' => IncidentResponseStatus::Failed, 'response_attempted_at' => now()->subHours(2)]);
        $latest = $this->incident(['status' => IncidentResponseStatus::Failed, 'response_attempted_at' => now()->subHours(1)]);

        $this->assertSame(30, $latest->failureBackoffMinutes());
    }

    public function test_is_redispatch_suppressed_uses_latest_incident(): void
    {
        // An old escalated-unacknowledged incident WOULD suppress, but a newer
        // resolved-and-past-cooldown incident is the one that counts.
        $this->incident([
            'status' => IncidentResponseStatus::Escalated,
            'escalated_at' => now()->subDays(2),
            'response_attempted_at' => now()->subDays(2),
            'acknowledged_at' => null,
        ]);
        $this->incident([
            'status' => IncidentResponseStatus::Resolved,
            'resolved_at' => now()->subHour(),
            'response_attempted_at' => now()->subHour(),
        ]);

        $this->assertFalse(
            IncidentResponse::isRedispatchSuppressed($this->site->id, IncidentTriggerType::Vulnerability)
        );
    }

    public function test_is_redispatch_suppressed_false_with_no_incident(): void
    {
        $this->assertFalse(
            IncidentResponse::isRedispatchSuppressed($this->site->id, IncidentTriggerType::SecurityCritical)
        );
    }
}
