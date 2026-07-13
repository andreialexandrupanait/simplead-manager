<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Contracts\WordPressApiServiceInterface;
use App\Jobs\FetchPhpErrorLogs;
use App\Models\Site;
use App\Services\WordPressApiServiceFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P2-47: FetchPhpErrorLogs used to swallow every exception, so a broken connector
 * endpoint / auth failure was completely invisible. A genuine fetch failure must
 * now surface (rethrown so the queue records it, logged at error level via
 * failed()), while an empty-but-successful fetch stays a normal success.
 */
class FetchPhpErrorLogsFailureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function bindApiThrowing(\Throwable $e): void
    {
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('getErrorLogs')->willThrowException($e);

        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));
    }

    private function bindApiReturning(array $entries): void
    {
        $api = $this->createMock(WordPressApiServiceInterface::class);
        $api->method('getErrorLogs')->willReturn(['entries' => $entries]);

        $this->app->instance(WordPressApiServiceFactory::class, $this->createMockApiFactory($api));
    }

    public function test_genuine_fetch_failure_is_not_swallowed(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        $this->bindApiThrowing(new \RuntimeException('connector 500 / auth failure'));

        Log::spy();

        $this->expectException(\RuntimeException::class);

        try {
            (new FetchPhpErrorLogs($site))->handle();
        } finally {
            // The failure is logged at error level (not silently swallowed as before).
            Log::shouldHaveReceived('error')->once();
        }
    }

    public function test_failed_handler_records_the_failure_at_error_level(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        Log::spy();

        (new FetchPhpErrorLogs($site))->failed(new \RuntimeException('boom'));

        Log::shouldHaveReceived('error')->once();
    }

    public function test_empty_but_successful_fetch_is_treated_as_success(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);
        $this->bindApiReturning([]);

        // No exception, no rows — an empty window is a valid, successful result.
        (new FetchPhpErrorLogs($site))->handle();

        $this->assertDatabaseCount('php_error_logs', 0);
    }
}
