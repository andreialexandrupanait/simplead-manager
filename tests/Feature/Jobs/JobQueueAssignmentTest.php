<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\CreateStatusPageIncident;
use App\Jobs\ProcessNotificationEscalations;
use App\Jobs\ResolveStatusPageIncident;
use App\Jobs\RunSecurityScan;
use App\Jobs\SendDailyDigest;
use App\Models\Site;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * P2-70: latency-sensitive notification / security jobs must run on their own
 * dedicated Horizon supervisors, never the shared low-priority `default` queue
 * (which sits behind 900s SEO crawls on supervisor-general).
 */
class JobQueueAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_process_notification_escalations_uses_notifications_queue(): void
    {
        $this->assertSame('notifications', (new ProcessNotificationEscalations)->queue);
    }

    public function test_send_daily_digest_uses_notifications_queue(): void
    {
        $this->assertSame('notifications', (new SendDailyDigest)->queue);
    }

    public function test_create_status_page_incident_uses_notifications_queue(): void
    {
        $site = Site::factory()->create();

        $this->assertSame('notifications', (new CreateStatusPageIncident($site, 'down'))->queue);
    }

    public function test_resolve_status_page_incident_uses_notifications_queue(): void
    {
        $site = Site::factory()->create();

        $this->assertSame('notifications', (new ResolveStatusPageIncident($site))->queue);
    }

    public function test_run_security_scan_uses_security_queue(): void
    {
        $site = Site::factory()->create();

        $this->assertSame('security', (new RunSecurityScan($site))->queue);
    }

    public function test_no_target_job_is_left_on_the_default_queue(): void
    {
        $site = Site::factory()->create();

        foreach ([
            new ProcessNotificationEscalations,
            new SendDailyDigest,
            new CreateStatusPageIncident($site, 'down'),
            new ResolveStatusPageIncident($site),
            new RunSecurityScan($site),
        ] as $job) {
            $this->assertNotSame('default', $job->queue, get_class($job).' must not run on the default queue');
            $this->assertNotNull($job->queue);
        }
    }
}
