<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Jobs\FetchPhpErrorLogs;
use App\Models\PhpErrorLog;
use App\Models\Site;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P1-51: the connector re-parses the same rolling log window every 6h and returns
 * the same aggregated entries. Ingestion must be idempotent — repeated fetches
 * must not inflate `count` (previously it ADDED the returned count every run) and
 * must not resurrect resolved errors (previously it force-reset is_resolved=false).
 */
class FetchPhpErrorLogsIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function bindApiReturning(array $entries): void
    {
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('getErrorLogs')->willReturn(['entries' => $entries]);

        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));
    }

    public function test_repeated_fetch_of_the_same_window_does_not_inflate_count(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        $entry = [
            'level' => 'warning',
            'message' => 'Undefined variable $foo',
            'file' => '/var/www/wp-content/plugins/x/x.php',
            'line' => 42,
            'count' => 3,
            'timestamp' => '05-Jul-2026 10:00:00 UTC',
            'last_seen' => '05-Jul-2026 12:00:00 UTC',
        ];

        $this->bindApiReturning([$entry]);

        (new FetchPhpErrorLogs($site))->handle();
        (new FetchPhpErrorLogs($site))->handle();
        (new FetchPhpErrorLogs($site))->handle();

        $this->assertDatabaseCount('php_error_logs', 1);

        $row = PhpErrorLog::where('site_id', $site->id)->firstOrFail();
        $this->assertSame(3, $row->count, 'Count must not inflate across identical fetches.');
    }

    public function test_newer_occurrence_advances_count_via_max_not_sum(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        $this->bindApiReturning([[
            'level' => 'fatal',
            'message' => 'Boom',
            'count' => 5,
            'timestamp' => '05-Jul-2026 10:00:00 UTC',
            'last_seen' => '05-Jul-2026 10:00:00 UTC',
        ]]);
        (new FetchPhpErrorLogs($site))->handle();

        // Same error, but the window now reports more occurrences at a later time.
        $this->bindApiReturning([[
            'level' => 'fatal',
            'message' => 'Boom',
            'count' => 8,
            'timestamp' => '05-Jul-2026 10:00:00 UTC',
            'last_seen' => '05-Jul-2026 14:00:00 UTC',
        ]]);
        (new FetchPhpErrorLogs($site))->handle();

        $row = PhpErrorLog::where('site_id', $site->id)->firstOrFail();
        $this->assertSame(8, $row->count, 'Advancing window count is the max, not a running sum.');
        $this->assertSame('2026-07-05 14:00:00', $row->last_seen_at->format('Y-m-d H:i:s'));
    }

    public function test_resolved_error_is_not_resurrected_by_a_repeated_fetch(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        $entry = [
            'level' => 'fatal',
            'message' => 'Already handled',
            'count' => 2,
            'timestamp' => '05-Jul-2026 10:00:00 UTC',
            'last_seen' => '05-Jul-2026 10:00:00 UTC',
        ];

        $this->bindApiReturning([$entry]);
        (new FetchPhpErrorLogs($site))->handle();

        // Operator resolves it.
        PhpErrorLog::where('site_id', $site->id)->update(['is_resolved' => true]);

        // Next 6h fetch returns the same (still-in-window) entry.
        (new FetchPhpErrorLogs($site))->handle();

        $row = PhpErrorLog::where('site_id', $site->id)->firstOrFail();
        $this->assertTrue($row->is_resolved, 'A repeated fetch of the same window must not resurrect a resolved error.');
    }

    public function test_overlong_file_path_does_not_abort_ingestion(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        $this->bindApiReturning([[
            'level' => 'notice',
            'message' => 'Long path error',
            'file' => str_repeat('a', 900),
            'line' => 1,
            'count' => 1,
            'timestamp' => '05-Jul-2026 10:00:00 UTC',
            'last_seen' => '05-Jul-2026 10:00:00 UTC',
        ]]);

        (new FetchPhpErrorLogs($site))->handle();

        $this->assertDatabaseCount('php_error_logs', 1);
        $row = PhpErrorLog::where('site_id', $site->id)->firstOrFail();
        $this->assertLessThanOrEqual(255, strlen((string) $row->file));
    }
}
