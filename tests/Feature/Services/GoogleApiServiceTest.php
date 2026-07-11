<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Jobs\SendNotificationJob;
use App\Models\GoogleConnection;
use App\Models\NotificationChannel;
use App\Services\GoogleApiService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * P0-23: a transient Google token-refresh failure (429/5xx) permanently and
 * silently deactivated the connection. Now only permanent auth errors
 * (invalid_grant / other 4xx) deactivate, transient errors retry, and a real
 * deactivation fires a notification.
 */
class GoogleApiServiceTest extends TestCase
{
    use RefreshDatabase;

    private function expiredConnection(): GoogleConnection
    {
        // Tokens are double-encrypted (encrypted cast + manual encrypt), so the
        // stored value must itself be ciphertext for the service to decrypt it.
        return GoogleConnection::factory()->create([
            'is_active' => true,
            'token_expires_at' => now()->subHour(),
            'access_token' => encrypt('old-access'),
            'refresh_token' => encrypt('refresh-token'),
        ]);
    }

    public function test_transient_failure_does_not_deactivate_and_does_not_notify(): void
    {
        Queue::fake();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'rateLimitExceeded'], 429),
        ]);

        NotificationChannel::factory()->create(['is_default' => true, 'is_active' => true]);

        $conn = $this->expiredConnection();

        try {
            new GoogleApiService($conn);
            $this->fail('Expected a transient failure to throw for retry.');
        } catch (\Throwable $e) {
            // expected — the job will retry
        }

        $this->assertTrue($conn->fresh()->is_active, 'Transient failure must not deactivate the connection.');
        Queue::assertNotPushed(SendNotificationJob::class);
    }

    public function test_server_error_is_treated_as_transient(): void
    {
        Queue::fake();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response('upstream error', 503),
        ]);

        $conn = $this->expiredConnection();

        try {
            new GoogleApiService($conn);
            $this->fail('Expected a 503 to throw for retry.');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertTrue($conn->fresh()->is_active);
    }

    public function test_permanent_auth_failure_deactivates_and_notifies(): void
    {
        Queue::fake();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400),
        ]);

        NotificationChannel::factory()->create(['is_default' => true, 'is_active' => true]);

        $conn = $this->expiredConnection();

        try {
            new GoogleApiService($conn);
            $this->fail('Expected a permanent auth failure to throw.');
        } catch (\Throwable $e) {
            // expected
        }

        $this->assertFalse($conn->fresh()->is_active, 'invalid_grant must deactivate the connection.');
        Queue::assertPushed(SendNotificationJob::class);
    }

    public function test_successful_refresh_keeps_connection_active(): void
    {
        Queue::fake();
        Http::fake([
            'oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'fresh-access',
                'expires_in' => 3600,
            ], 200),
        ]);

        $conn = $this->expiredConnection();

        new GoogleApiService($conn);

        $this->assertTrue($conn->fresh()->is_active);
        Queue::assertNotPushed(SendNotificationJob::class);
    }
}
