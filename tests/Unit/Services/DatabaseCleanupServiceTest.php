<?php

namespace Tests\Unit\Services;

use App\Models\ActivityLog;
use App\Models\DatabaseCleanup;
use App\Services\DatabaseCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;
use Tests\Traits\CreatesSite;
use Tests\Traits\MocksWordPressApi;

class DatabaseCleanupServiceTest extends TestCase
{
    use RefreshDatabase, CreatesSite, MocksWordPressApi;

    private function fakeDbCleanupApi(): void
    {
        Http::fake([
            '*/wp-json/simplead/v1/db-cleanup-stats' => Http::response([
                'revisions' => 150,
                'auto_drafts' => 10,
                'trash_posts' => 5,
                'spam_comments' => 20,
                'trash_comments' => 8,
                'transients' => 45,
                'orphaned_meta' => 12,
            ]),
            '*/wp-json/simplead/v1/db-cleanup-run' => Http::response([
                'revisions_deleted' => 150,
                'auto_drafts_deleted' => 10,
                'trash_posts_deleted' => 5,
                'spam_comments_deleted' => 20,
                'trash_comments_deleted' => 8,
                'transients_deleted' => 45,
                'orphaned_meta_deleted' => 12,
                'space_saved' => 5242880,
            ]),
            '*' => Http::response([]),
        ]);
    }

    public function test_get_stats_returns_cleanup_statistics_from_api(): void
    {
        $this->fakeDbCleanupApi();
        $site = $this->createSite();

        $stats = DatabaseCleanupService::getStats($site);

        $this->assertIsArray($stats);
        $this->assertArrayHasKey('revisions', $stats);
        $this->assertArrayHasKey('auto_drafts', $stats);
        $this->assertArrayHasKey('spam_comments', $stats);
        $this->assertArrayHasKey('transients', $stats);
    }

    public function test_run_creates_database_cleanup_record(): void
    {
        $this->fakeDbCleanupApi();
        $site = $this->createSite();

        $cleanup = DatabaseCleanupService::run($site, ['revisions' => true]);

        $this->assertInstanceOf(DatabaseCleanup::class, $cleanup);
        $this->assertDatabaseHas('database_cleanups', [
            'site_id' => $site->id,
            'status' => 'completed',
        ]);
    }

    public function test_run_returns_cleanup_results(): void
    {
        $this->fakeDbCleanupApi();
        $site = $this->createSite();

        $cleanup = DatabaseCleanupService::run($site, ['revisions' => true]);

        $this->assertEquals('completed', $cleanup->status);
        $this->assertEquals(150, $cleanup->revisions_deleted);
        $this->assertEquals(10, $cleanup->auto_drafts_deleted);
        $this->assertEquals(5242880, $cleanup->space_saved);
    }

    public function test_run_creates_activity_log_entry(): void
    {
        $this->fakeDbCleanupApi();
        $site = $this->createSite();

        DatabaseCleanupService::run($site, ['revisions' => true]);

        $this->assertDatabaseHas('activity_logs', [
            'site_id' => $site->id,
            'type' => 'database',
            'severity' => 'info',
        ]);
    }

    public function test_run_handles_api_failure_gracefully(): void
    {
        $site = $this->createSite();

        Http::fake([
            '*/wp-json/simplead/v1/db-cleanup-run' => Http::response(null, 500),
            '*/wp-json/simplead/v1/*' => Http::response([]),
        ]);

        $cleanup = DatabaseCleanupService::run($site, ['revisions' => true]);

        $this->assertInstanceOf(DatabaseCleanup::class, $cleanup);
        $this->assertEquals('failed', $cleanup->status);
        $this->assertNotNull($cleanup->error_message);
    }
}
