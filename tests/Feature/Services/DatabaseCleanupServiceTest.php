<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Models\Site;
use App\Services\DatabaseCleanupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DatabaseCleanupServiceTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();
        $this->site = Site::factory()->create();
    }

    public function test_get_stats_aggregates_orphaned_meta(): void
    {
        $api = $this->createMockApi();
        $api->method('getDbCleanupStats')->willReturn([
            'stats' => [
                'revisions' => 50,
                'orphaned_postmeta' => 10,
                'orphaned_commentmeta' => 5,
                'orphaned_usermeta' => 3,
                'orphaned_termmeta' => 2,
            ],
        ]);

        $service = new DatabaseCleanupService($this->createMockApiFactory($api));
        $stats = $service->getStats($this->site);

        $this->assertSame(20, $stats['orphaned_meta']);
        $this->assertSame(50, $stats['revisions']);
    }

    public function test_get_stats_normalizes_key_names(): void
    {
        $api = $this->createMockApi();
        $api->method('getDbCleanupStats')->willReturn([
            'expired_transients' => 100,
            'trashed_posts' => 25,
        ]);

        $service = new DatabaseCleanupService($this->createMockApiFactory($api));
        $stats = $service->getStats($this->site);

        $this->assertSame(100, $stats['transients']);
        $this->assertSame(25, $stats['trash_posts']);
    }

    public function test_run_creates_cleanup_record_on_success(): void
    {
        $api = $this->createMockApi();
        $api->method('runDbCleanup')->willReturn([
            'cleaned' => [
                'revisions' => 50,
                'auto_drafts' => 10,
                'trashed_posts' => 5,
                'spam_comments' => 20,
                'trashed_comments' => 15,
                'expired_transients' => 100,
                'orphaned_postmeta' => 10,
                'orphaned_commentmeta' => 5,
                'orphaned_usermeta' => 0,
                'orphaned_termmeta' => 0,
                'space_saved' => 5242880,
            ],
        ]);

        $service = new DatabaseCleanupService($this->createMockApiFactory($api));
        $cleanup = $service->run($this->site, ['revisions' => true]);

        $this->assertSame('completed', $cleanup->status);
        $this->assertSame(50, $cleanup->revisions_deleted);
        $this->assertSame(15, $cleanup->orphaned_meta_deleted);
        $this->assertSame(5242880, $cleanup->space_saved);
    }

    public function test_run_creates_failed_record_on_api_error(): void
    {
        $api = $this->createMockApi();
        $api->method('runDbCleanup')->willThrowException(new \RuntimeException('Connection refused'));

        $service = new DatabaseCleanupService($this->createMockApiFactory($api));
        $cleanup = $service->run($this->site, ['revisions' => true]);

        $this->assertSame('failed', $cleanup->status);
        $this->assertSame('Connection refused', $cleanup->error_message);
    }

    public function test_optimize_table_returns_success(): void
    {
        $api = $this->createMockApi();
        $api->expects($this->once())->method('optimizeTable')->with('wp_posts');

        $service = new DatabaseCleanupService($this->createMockApiFactory($api));
        $result = $service->optimizeTable($this->site, 'wp_posts');

        $this->assertTrue($result['success']);
    }

    public function test_delete_table_returns_failure_on_error(): void
    {
        $api = $this->createMockApi();
        $api->method('deleteTable')->willThrowException(new \RuntimeException('Access denied'));

        $service = new DatabaseCleanupService($this->createMockApiFactory($api));
        $result = $service->deleteTable($this->site, 'wp_old_table');

        $this->assertFalse($result['success']);
        $this->assertSame('Access denied', $result['message']);
    }

    public function test_convert_table_engine_success(): void
    {
        $api = $this->createMockApi();
        $api->expects($this->once())->method('convertTableEngine')->with('wp_options');

        $service = new DatabaseCleanupService($this->createMockApiFactory($api));
        $result = $service->convertTableEngine($this->site, 'wp_options');

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('InnoDB', $result['message']);
    }
}
