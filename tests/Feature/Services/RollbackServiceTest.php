<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\RollbackPoint;
use App\Models\Site;
use App\Services\RollbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RollbackServiceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create();
    }

    public function test_create_rollback_point(): void
    {
        $service = new RollbackService($this->createMockApiFactory());

        $point = $service->createRollbackPoint($this->site, 'plugin', 'yoast-seo', '20.0', '21.0');

        $this->assertSame('available', $point->status);
        $this->assertSame('plugin', $point->type);
        $this->assertSame('yoast-seo', $point->slug);
        $this->assertSame('20.0', $point->from_version);
        $this->assertSame('21.0', $point->to_version);
        $this->assertNotNull($point->expires_at);
        $this->assertTrue($point->expires_at->isAfter(now()->addDays(29)));
    }

    public function test_execute_rollback_marks_used_and_creates_log(): void
    {
        Queue::fake();

        $point = RollbackPoint::factory()->available()->create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'yoast-seo',
            'from_version' => '20.0',
            'to_version' => '21.0',
        ]);

        $api = $this->createMockApi();
        $api->method('rollback')->willReturn(['success' => true]);

        $service = new RollbackService($this->createMockApiFactory($api));
        $result = $service->executeRollback($point);

        $point->refresh();
        $this->assertSame('used', $point->status);
        $this->assertTrue($result['success']);

        $this->assertDatabaseHas('update_logs', [
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'yoast-seo',
            'from_version' => '21.0',
            'to_version' => '20.0',
        ]);

        Queue::assertPushed(\App\Jobs\SyncWordPressSite::class);
    }

    public function test_execute_rollback_failure_does_not_consume_point_and_logs_failure(): void
    {
        // P2-28: a failed rollback (explicit success=false) must NOT burn the
        // rollback point and must be recorded as a failure — the point stays
        // usable so a retry can recover the site.
        Queue::fake();

        $point = RollbackPoint::factory()->available()->create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'yoast-seo',
            'from_version' => '20.0',
            'to_version' => '21.0',
        ]);

        $api = $this->createMockApi();
        $api->method('rollback')->willReturn([
            'success' => false,
            'error' => ['code' => 'ROLLBACK_FAILED', 'message' => 'Download 404'],
        ]);

        $service = new RollbackService($this->createMockApiFactory($api));
        $result = $service->executeRollback($point);

        $point->refresh();
        $this->assertSame('available', $point->status);
        $this->assertFalse($result['success']);
        $this->assertDatabaseHas('update_logs', [
            'site_id' => $this->site->id,
            'slug' => 'yoast-seo',
            'success' => false,
        ]);
    }

    public function test_execute_rollback_empty_payload_does_not_consume_point(): void
    {
        // P2-28: an ambiguous/empty payload (no success flag) must NOT be defaulted
        // to success — the point stays usable and the log records failure.
        Queue::fake();

        $point = RollbackPoint::factory()->available()->create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'yoast-seo',
            'from_version' => '20.0',
            'to_version' => '21.0',
        ]);

        $api = $this->createMockApi();
        $api->method('rollback')->willReturn([]);

        $service = new RollbackService($this->createMockApiFactory($api));
        $service->executeRollback($point);

        $point->refresh();
        $this->assertSame('available', $point->status);
        $this->assertDatabaseHas('update_logs', [
            'site_id' => $this->site->id,
            'slug' => 'yoast-seo',
            'success' => false,
        ]);
    }

    public function test_execute_rollback_calls_api_with_correct_params(): void
    {
        Queue::fake();

        $point = RollbackPoint::factory()->create([
            'site_id' => $this->site->id,
            'type' => 'plugin',
            'slug' => 'yoast-seo',
            'from_version' => '20.0',
            'to_version' => '21.0',
        ]);

        $api = $this->createMockApi();
        $api->expects($this->once())
            ->method('rollback')
            ->with('plugin', 'yoast-seo', '20.0')
            ->willReturn(['success' => true]);

        $service = new RollbackService($this->createMockApiFactory($api));
        $service->executeRollback($point);
    }

    public function test_clean_expired_marks_expired(): void
    {
        $expired = RollbackPoint::factory()->create([
            'site_id' => $this->site->id,
            'status' => 'available',
            'expires_at' => now()->subDay(),
        ]);
        $valid = RollbackPoint::factory()->create([
            'site_id' => $this->site->id,
            'status' => 'available',
            'expires_at' => now()->addDays(10),
        ]);

        $service = new RollbackService($this->createMockApiFactory());
        $count = $service->cleanExpired();

        $this->assertSame(1, $count);
        $expired->refresh();
        $valid->refresh();
        $this->assertSame('expired', $expired->status);
        $this->assertSame('available', $valid->status);
    }

    public function test_get_available_points_filters_correctly(): void
    {
        RollbackPoint::factory()->available()->create(['site_id' => $this->site->id]);
        RollbackPoint::factory()->used()->create(['site_id' => $this->site->id]);
        RollbackPoint::factory()->expired()->create(['site_id' => $this->site->id]);

        $otherSite = Site::factory()->create();
        RollbackPoint::factory()->available()->create(['site_id' => $otherSite->id]);

        $service = new RollbackService($this->createMockApiFactory());
        $points = $service->getAvailablePoints($this->site);

        $this->assertCount(1, $points);
    }

    public function test_get_available_points_ordered_by_newest(): void
    {
        $old = RollbackPoint::factory()->available()->create([
            'site_id' => $this->site->id,
            'created_at' => now()->subDays(5),
        ]);
        $new = RollbackPoint::factory()->available()->create([
            'site_id' => $this->site->id,
            'created_at' => now()->subDay(),
        ]);

        $service = new RollbackService($this->createMockApiFactory());
        $points = $service->getAvailablePoints($this->site);

        $this->assertSame($new->id, $points->first()->id);
    }

    public function test_clean_expired_does_not_touch_used(): void
    {
        $used = RollbackPoint::factory()->create([
            'site_id' => $this->site->id,
            'status' => 'used',
            'expires_at' => now()->subDay(),
        ]);

        $service = new RollbackService($this->createMockApiFactory());
        $count = $service->cleanExpired();

        $this->assertSame(0, $count);
        $used->refresh();
        $this->assertSame('used', $used->status);
    }

    public function test_execute_rollback_dispatches_sync_job(): void
    {
        Queue::fake();

        $point = RollbackPoint::factory()->create(['site_id' => $this->site->id]);
        $api = $this->createMockApi();
        $api->method('rollback')->willReturn(['success' => true]);

        $service = new RollbackService($this->createMockApiFactory($api));
        $service->executeRollback($point);

        Queue::assertPushed(\App\Jobs\SyncWordPressSite::class, function ($job) {
            return true;
        });
    }
}
