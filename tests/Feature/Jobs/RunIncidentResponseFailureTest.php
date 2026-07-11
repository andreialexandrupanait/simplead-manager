<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Enums\IncidentResponseStatus;
use App\Enums\IncidentTriggerType;
use App\Jobs\RunIncidentResponse;
use App\Models\IncidentResponse;
use App\Models\Site;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * SEC-A2-11: a killed/timed-out worker left the IncidentResponse row
 * non-terminal forever, silently extending the dispatcher's cooldown window.
 */
class RunIncidentResponseFailureTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_marks_the_orphaned_incident_terminal(): void
    {
        $site = Site::factory()->create();
        $incident = IncidentResponse::factory()->create([
            'site_id' => $site->id,
            'trigger_type' => IncidentTriggerType::SecurityCritical,
            'status' => IncidentResponseStatus::Executing,
        ]);

        $job = new RunIncidentResponse($site, IncidentTriggerType::SecurityCritical, 'SecurityIssue', 1);
        $job->failed(new \RuntimeException('killed'));

        $fresh = $incident->fresh();
        $this->assertSame(IncidentResponseStatus::Failed, $fresh->status);
        $this->assertStringContainsString('Job terminated: killed', $fresh->summary);
    }

    public function test_failed_does_not_touch_terminal_incidents(): void
    {
        $site = Site::factory()->create();
        $incident = IncidentResponse::factory()->resolved()->create([
            'site_id' => $site->id,
            'trigger_type' => IncidentTriggerType::SecurityCritical,
        ]);

        (new RunIncidentResponse($site, IncidentTriggerType::SecurityCritical, 'SecurityIssue', 1))
            ->failed(new \RuntimeException('killed'));

        $this->assertSame(IncidentResponseStatus::Resolved, $incident->fresh()->status);
    }

    public function test_stale_sweep_fails_old_non_terminal_incidents_and_spares_fresh_ones(): void
    {
        $stale = IncidentResponse::factory()->create(['status' => IncidentResponseStatus::Diagnosing]);
        DB::table('incident_responses')->where('id', $stale->id)
            ->update(['created_at' => now()->subMinutes(45)]);

        $fresh = IncidentResponse::factory()->create(['status' => IncidentResponseStatus::Executing]);

        $this->runScheduledSweep();

        $this->assertSame(IncidentResponseStatus::Failed, $stale->fresh()->status);
        $this->assertStringContainsString('auto-swept', $stale->fresh()->summary);
        $this->assertSame(IncidentResponseStatus::Executing, $fresh->fresh()->status);
    }

    private function runScheduledSweep(): void
    {
        $event = collect(app(Schedule::class)->events())
            ->first(fn ($e) => str_contains((string) $e->description, 'incident-response-stale-sweep'));

        $this->assertNotNull($event, 'incident-response-stale-sweep is not scheduled');

        $event->run(app());
    }
}
