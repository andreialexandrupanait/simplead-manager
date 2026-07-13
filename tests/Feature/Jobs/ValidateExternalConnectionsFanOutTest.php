<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Jobs\ValidateConnection;
use App\Jobs\ValidateExternalConnections;
use App\Models\CloudflareConnection;
use App\Models\GoogleConnection;
use App\Models\Site;
use App\Models\StorageDestination;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P2-64: ValidateExternalConnections used to validate every connection inline,
 * serially, with tries=1 — so at fleet scale it blew past its own timeout and
 * was SIGKILLed mid-run. It must now fan out one bounded ValidateConnection job
 * per connection instead of validating them all in-process.
 */
class ValidateExternalConnectionsFanOutTest extends TestCase
{
    use RefreshDatabase;

    public function test_dispatcher_fans_out_one_job_per_connection(): void
    {
        Queue::fake();

        GoogleConnection::factory()->count(2)->create(['is_active' => true]);
        GoogleConnection::factory()->create(['is_active' => false]); // excluded
        CloudflareConnection::factory()->create();
        StorageDestination::factory()->create(['type' => 's3', 'is_active' => true]);
        StorageDestination::factory()->create(['type' => 'local', 'is_active' => true]); // excluded
        Site::factory()->create(['is_connected' => true, 'api_key' => 'k-'.uniqid()]);
        Site::factory()->create(['is_connected' => false]); // excluded

        (new ValidateExternalConnections)->handle();

        // 2 google + 1 cloudflare + 1 storage + 1 wordpress = 5 fanned-out jobs.
        Queue::assertPushed(ValidateConnection::class, 5);
        Queue::assertPushedOn('sync', ValidateConnection::class);

        $this->assertPushedCountForType(ValidateConnection::TYPE_GOOGLE, 2);
        $this->assertPushedCountForType(ValidateConnection::TYPE_CLOUDFLARE, 1);
        $this->assertPushedCountForType(ValidateConnection::TYPE_STORAGE, 1);
        $this->assertPushedCountForType(ValidateConnection::TYPE_WORDPRESS, 1);
    }

    private function assertPushedCountForType(string $type, int $expected): void
    {
        $count = 0;
        Queue::assertPushed(ValidateConnection::class, function ($job) use ($type, &$count) {
            if ($job->type === $type) {
                $count++;
            }

            return true;
        });

        $this->assertSame($expected, $count, "Expected {$expected} {$type} validation jobs.");
    }

    public function test_per_connection_job_validates_one_and_records_failure(): void
    {
        Queue::fake();

        Http::fake([
            '*/user/tokens/verify' => Http::response([
                'success' => false,
                'errors' => [['code' => 1000, 'message' => 'Invalid token.']],
            ], 401),
        ]);

        $conn = CloudflareConnection::factory()->create(['is_valid' => true]);

        (new ValidateConnection(ValidateConnection::TYPE_CLOUDFLARE, $conn->id))->handle();

        // The single connection's failure was recorded.
        $this->assertDatabaseHas('activity_logs', [
            'type' => 'connection_error',
            'severity' => 'warning',
        ]);
        // And the token was flipped invalid by the validation.
        $this->assertFalse($conn->fresh()->is_valid);
    }

    public function test_per_connection_job_is_a_noop_for_a_missing_connection(): void
    {
        Queue::fake();

        // No exception, nothing recorded — the row was deleted between fan-out
        // and execution.
        (new ValidateConnection(ValidateConnection::TYPE_CLOUDFLARE, 999999))->handle();

        $this->assertDatabaseCount('activity_logs', 0);
    }
}
