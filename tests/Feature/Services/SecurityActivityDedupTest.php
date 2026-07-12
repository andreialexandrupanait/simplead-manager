<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\PullSecurityActivityLogs;
use App\Models\Site;
use App\Services\SecurityActivityService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P2-46: audit-trail ingestion must be idempotent. Overlapping pulls, retries, or an
 * inclusive `since` cursor re-fetching the boundary event could otherwise insert
 * duplicate rows. A (site_id, dedup_hash) unique index + insertOrIgnore collapse a
 * re-ingested event to a single row, and the pull job is ShouldBeUnique.
 */
class SecurityActivityDedupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function event(): array
    {
        return [
            'event_type' => 'failed_login',
            'username' => 'admin',
            'object_type' => null,
            'object_name' => 'Brute force attempt',
            'action' => 'login',
            'ip_address' => '203.0.113.9',
            'user_agent' => 'curl/8.0',
            'details' => ['tries' => 3],
            'occurred_at' => '2026-07-05 10:00:00',
        ];
    }

    public function test_ingesting_the_same_event_twice_yields_one_row(): void
    {
        $site = Site::factory()->create();
        $service = app(SecurityActivityService::class);

        $first = $service->ingestLogs($site, [$this->event()]);
        $second = $service->ingestLogs($site, [$this->event()]);

        $this->assertSame(1, $first);
        $this->assertSame(0, $second, 'A re-ingested identical event must be a no-op.');
        $this->assertDatabaseCount('security_activity_logs', 1);
    }

    public function test_distinct_events_are_still_stored_separately(): void
    {
        $site = Site::factory()->create();
        $service = app(SecurityActivityService::class);

        $a = $this->event();
        $b = $this->event();
        $b['username'] = 'editor'; // different subject → different hash

        $service->ingestLogs($site, [$a, $b]);

        $this->assertDatabaseCount('security_activity_logs', 2);
    }

    public function test_a_duplicate_within_a_single_batch_is_deduped(): void
    {
        $site = Site::factory()->create();
        $service = app(SecurityActivityService::class);

        $inserted = $service->ingestLogs($site, [$this->event(), $this->event()]);

        $this->assertSame(1, $inserted);
        $this->assertDatabaseCount('security_activity_logs', 1);
    }

    public function test_pull_job_is_should_be_unique(): void
    {
        $this->assertInstanceOf(
            ShouldBeUnique::class,
            new PullSecurityActivityLogs(Site::factory()->create()),
        );
    }
}
