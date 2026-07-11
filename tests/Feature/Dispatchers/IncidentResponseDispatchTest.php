<?php

declare(strict_types=1);

namespace Tests\Feature\Dispatchers;

use App\Dispatchers\IncidentResponseDispatcher;
use App\Enums\IncidentResponseStatus;
use App\Enums\IncidentTriggerType;
use App\Jobs\RunIncidentResponse;
use App\Models\IncidentResponse;
use App\Models\SecurityIssue;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Models\VulnerabilityAlert;
use App\Services\IncidentResponse\ContextGatherer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * SEC-A2-04: the dispatcher and the AI context gatherer queried columns that
 * do not exist (SecurityIssue.status, VulnerabilityAlert.plugin_slug/cvss_score)
 * and crashed with SQLSTATE 42703 the moment incident response was enabled.
 * These tests EXECUTE the real queries on Postgres so a schema drift fails loudly.
 */
class IncidentResponseDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function eligibleSite(): Site
    {
        $site = Site::factory()->create(['is_connected' => true]);
        SiteHealthState::create(['site_id' => $site->id]); // circuit closed, monitoring enabled

        return $site;
    }

    public function test_open_critical_security_issue_dispatches_an_incident_response(): void
    {
        Queue::fake();
        config(['incident-response.enabled' => true]);

        $issue = SecurityIssue::factory()->create([
            'site_id' => $this->eligibleSite()->id,
            'severity' => 'critical',
            'is_fixed' => false,
            'is_ignored' => false,
        ]);

        (new IncidentResponseDispatcher)();

        Queue::assertPushed(RunIncidentResponse::class, fn ($job) => $job->triggerSourceId === $issue->id);
    }

    public function test_fixed_or_ignored_issues_do_not_dispatch(): void
    {
        Queue::fake();
        config(['incident-response.enabled' => true]);

        $site = $this->eligibleSite();
        SecurityIssue::factory()->create(['site_id' => $site->id, 'is_fixed' => true]);
        SecurityIssue::factory()->create(['site_id' => $site->id, 'is_ignored' => true]);

        (new IncidentResponseDispatcher)();

        Queue::assertNotPushed(RunIncidentResponse::class);
    }

    public function test_active_fixable_vulnerability_dispatches_an_incident_response(): void
    {
        Queue::fake();
        config(['incident-response.enabled' => true]);

        $alert = VulnerabilityAlert::factory()->create([
            'site_id' => $this->eligibleSite()->id,
            'status' => 'active',
            'fixed_in_version' => '2.0.0',
        ]);

        (new IncidentResponseDispatcher)();

        Queue::assertPushed(RunIncidentResponse::class, fn ($job) => $job->triggerSourceId === $alert->id);
    }

    public function test_escalated_unacknowledged_incident_is_not_redispatched(): void
    {
        // P0-21: an escalated incident awaiting a human must not re-enter the
        // pipeline every tick, even long after the cooldown window has passed.
        Queue::fake();
        config(['incident-response.enabled' => true]);
        config(['incident-response.safety.cooldown_minutes' => 30]);

        $site = $this->eligibleSite();
        VulnerabilityAlert::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'fixed_in_version' => '2.0.0',
        ]);
        IncidentResponse::factory()->create([
            'site_id' => $site->id,
            'trigger_type' => IncidentTriggerType::Vulnerability,
            'status' => IncidentResponseStatus::Escalated,
            'escalated_at' => now()->subHours(3),
            'response_attempted_at' => now()->subHours(3), // well past cooldown
            'acknowledged_at' => null,
        ]);

        (new IncidentResponseDispatcher)();

        Queue::assertNotPushed(RunIncidentResponse::class);
    }

    public function test_acknowledged_escalated_incident_can_be_redispatched(): void
    {
        Queue::fake();
        config(['incident-response.enabled' => true]);
        config(['incident-response.safety.cooldown_minutes' => 30]);

        $site = $this->eligibleSite();
        VulnerabilityAlert::factory()->create([
            'site_id' => $site->id,
            'status' => 'active',
            'fixed_in_version' => '2.0.0',
        ]);
        IncidentResponse::factory()->create([
            'site_id' => $site->id,
            'trigger_type' => IncidentTriggerType::Vulnerability,
            'status' => IncidentResponseStatus::Escalated,
            'escalated_at' => now()->subHours(3),
            'response_attempted_at' => now()->subHours(3),
            'acknowledged_at' => now(),
        ]);

        (new IncidentResponseDispatcher)();

        Queue::assertPushed(RunIncidentResponse::class);
    }

    public function test_context_gatherer_queries_run_against_the_real_schema(): void
    {
        $site = $this->eligibleSite();
        SecurityIssue::factory()->create(['site_id' => $site->id]);
        VulnerabilityAlert::factory()->create(['site_id' => $site->id, 'software_slug' => 'vulnerable-plugin']);

        // is_connected=false skips the live diagnostic HTTP call — DB queries only
        $site->update(['is_connected' => false]);

        $context = (new ContextGatherer)->gather($site->fresh());

        $this->assertCount(1, $context['security_issues']);
        $this->assertCount(1, $context['vulnerabilities']);
        $this->assertSame('vulnerable-plugin', $context['vulnerabilities'][0]['software_slug']);
    }
}
