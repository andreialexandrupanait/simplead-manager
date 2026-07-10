<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\RecordHealthScores;
use App\Models\NotificationTemplate;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HealthScoreAndEventsTest extends TestCase
{
    use RefreshDatabase;

    /** E-17: sites.health_score must actually be written, not left NULL. */
    public function test_record_health_scores_persists_the_score_on_the_site(): void
    {
        $site = Site::factory()->create(['is_connected' => true, 'health_score' => null]);

        (new RecordHealthScores)->handle();

        $fresh = $site->fresh();
        $this->assertNotNull($fresh->health_score);
        $this->assertGreaterThanOrEqual(0, $fresh->health_score);
        $this->assertLessThanOrEqual(100, $fresh->health_score);
    }

    /** E-16: the critical restore_failed alert must be a subscribable event. */
    public function test_restore_failed_is_in_the_notification_catalog(): void
    {
        $this->assertArrayHasKey('restore_failed', NotificationTemplate::EVENTS);
        $this->assertArrayHasKey('horizon_stopped', NotificationTemplate::EVENTS);
    }
}
