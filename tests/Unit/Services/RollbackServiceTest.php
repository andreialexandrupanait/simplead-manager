<?php

namespace Tests\Unit\Services;

use App\Jobs\SyncWordPressSite;
use App\Models\RollbackPoint;
use App\Models\UpdateLog;
use App\Services\RollbackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CreatesSite;
use Tests\Traits\MocksWordPressApi;

class RollbackServiceTest extends TestCase
{
    use RefreshDatabase, CreatesSite, MocksWordPressApi;

    // ------------------------------------------------------------------ //
    //  createRollbackPoint
    // ------------------------------------------------------------------ //

    public function test_create_rollback_point_creates_record_with_available_status_and_30_day_expiry(): void
    {
        $site = $this->createSite();
        $service = new RollbackService();

        $point = $service->createRollbackPoint($site, 'plugin', 'akismet', '5.0', '5.1');

        $this->assertInstanceOf(RollbackPoint::class, $point);
        $this->assertDatabaseHas('rollback_points', [
            'id' => $point->id,
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'akismet',
            'from_version' => '5.0',
            'to_version' => '5.1',
            'status' => 'available',
        ]);
        // Expiry should be approximately 30 days from now
        $this->assertTrue($point->expires_at->isBetween(now()->addDays(29), now()->addDays(31)));
    }

    // ------------------------------------------------------------------ //
    //  executeRollback — marks point as used
    // ------------------------------------------------------------------ //

    public function test_execute_rollback_marks_point_as_used(): void
    {
        Bus::fake([SyncWordPressSite::class]);
        $site = $this->createSite();
        $this->fakeWordPressApi([
            '*/wp-json/simplead/v1/rollback/*' => Http::response(['success' => true]),
        ]);

        $point = RollbackPoint::factory()->create([
            'site_id' => $site->id,
            'status' => 'available',
            'type' => 'plugin',
            'slug' => 'akismet',
            'from_version' => '5.0',
            'to_version' => '5.1',
        ]);

        $service = new RollbackService();
        $service->executeRollback($point);

        $point->refresh();
        $this->assertEquals('used', $point->status);
    }

    // ------------------------------------------------------------------ //
    //  executeRollback — creates UpdateLog
    // ------------------------------------------------------------------ //

    public function test_execute_rollback_creates_update_log(): void
    {
        Bus::fake([SyncWordPressSite::class]);
        $site = $this->createSite();
        $this->fakeWordPressApi([
            '*/wp-json/simplead/v1/rollback/*' => Http::response(['success' => true]),
        ]);

        $point = RollbackPoint::factory()->create([
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'akismet',
            'from_version' => '5.0',
            'to_version' => '5.1',
        ]);

        $service = new RollbackService();
        $service->executeRollback($point);

        $this->assertDatabaseHas('update_logs', [
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'akismet',
            'from_version' => '5.1',   // reversed
            'to_version' => '5.0',     // reversed
            'success' => true,
        ]);
    }

    // ------------------------------------------------------------------ //
    //  executeRollback — dispatches SyncWordPressSite
    // ------------------------------------------------------------------ //

    public function test_execute_rollback_dispatches_sync_wordpress_site(): void
    {
        Bus::fake([SyncWordPressSite::class]);
        $site = $this->createSite();
        $this->fakeWordPressApi([
            '*/wp-json/simplead/v1/rollback/*' => Http::response(['success' => true]),
        ]);

        $point = RollbackPoint::factory()->create([
            'site_id' => $site->id,
            'type' => 'plugin',
            'slug' => 'akismet',
            'from_version' => '5.0',
            'to_version' => '5.1',
        ]);

        $service = new RollbackService();
        $service->executeRollback($point);

        Bus::assertDispatched(SyncWordPressSite::class);
    }

    // ------------------------------------------------------------------ //
    //  cleanExpired — marks expired points
    // ------------------------------------------------------------------ //

    public function test_clean_expired_marks_expired_points(): void
    {
        $site = $this->createSite();

        $expiredPoint = RollbackPoint::factory()->create([
            'site_id' => $site->id,
            'status' => 'available',
            'expires_at' => now()->subDay(),
        ]);

        $service = new RollbackService();
        $count = $service->cleanExpired();

        $this->assertEquals(1, $count);
        $expiredPoint->refresh();
        $this->assertEquals('expired', $expiredPoint->status);
    }

    // ------------------------------------------------------------------ //
    //  cleanExpired — does not affect non-expired points
    // ------------------------------------------------------------------ //

    public function test_clean_expired_does_not_affect_non_expired_points(): void
    {
        $site = $this->createSite();

        $validPoint = RollbackPoint::factory()->create([
            'site_id' => $site->id,
            'status' => 'available',
            'expires_at' => now()->addDays(15),
        ]);

        $service = new RollbackService();
        $service->cleanExpired();

        $validPoint->refresh();
        $this->assertEquals('available', $validPoint->status);
    }

    // ------------------------------------------------------------------ //
    //  getAvailablePoints — returns only available non-expired
    // ------------------------------------------------------------------ //

    public function test_get_available_points_returns_only_available_non_expired(): void
    {
        $site = $this->createSite();

        // Available and not expired
        $available = RollbackPoint::factory()->create([
            'site_id' => $site->id,
            'status' => 'available',
            'expires_at' => now()->addDays(15),
        ]);

        // Used — should not be returned
        RollbackPoint::factory()->create([
            'site_id' => $site->id,
            'status' => 'used',
            'expires_at' => now()->addDays(15),
        ]);

        // Expired — should not be returned
        RollbackPoint::factory()->create([
            'site_id' => $site->id,
            'status' => 'available',
            'expires_at' => now()->subDay(),
        ]);

        $service = new RollbackService();
        $points = $service->getAvailablePoints($site);

        $this->assertCount(1, $points);
        $this->assertEquals($available->id, $points->first()->id);
    }

    // ------------------------------------------------------------------ //
    //  getAvailablePoints — ordered by created_at desc
    // ------------------------------------------------------------------ //

    public function test_get_available_points_ordered_by_created_at_desc(): void
    {
        $site = $this->createSite();

        $older = RollbackPoint::factory()->create([
            'site_id' => $site->id,
            'status' => 'available',
            'expires_at' => now()->addDays(15),
            'created_at' => now()->subDays(5),
        ]);

        $newer = RollbackPoint::factory()->create([
            'site_id' => $site->id,
            'status' => 'available',
            'expires_at' => now()->addDays(15),
            'created_at' => now()->subDay(),
        ]);

        $service = new RollbackService();
        $points = $service->getAvailablePoints($site);

        $this->assertEquals($newer->id, $points->first()->id);
        $this->assertEquals($older->id, $points->last()->id);
    }
}
