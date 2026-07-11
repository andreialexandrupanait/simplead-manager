<?php

declare(strict_types=1);

namespace Tests\Feature\Jobs;

use App\Exceptions\WordPressApiException;
use App\Jobs\SendNotificationJob;
use App\Jobs\SyncWordPressSite;
use App\Models\NotificationChannel;
use App\Models\Site;
use GuzzleHttp\Psr7\Response as Psr7Response;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SyncWordPressSiteDisconnectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Redis::spy();
        // Prevent Site::created → FetchSiteFavicon (outbound HTTP) from running
        // on the sync queue, and capture notification jobs for assertions.
        Queue::fake();
        NotificationChannel::factory()->default()->create();
    }

    private function requestException(int $status): RequestException
    {
        $response = new Response(new Psr7Response($status, [], '{"message":"nope"}'));

        return new RequestException($response);
    }

    public function test_transient_failure_does_not_disconnect_the_site(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        // A connection reset / timeout carries no HTTP status.
        (new SyncWordPressSite($site))->failed(new \RuntimeException('cURL error 28: timed out'));

        $this->assertTrue($site->fresh()->is_connected, 'Transient failure must not disconnect the site.');
        Queue::assertNotPushed(SendNotificationJob::class);
    }

    public function test_server_error_5xx_does_not_disconnect_the_site(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        (new SyncWordPressSite($site))->failed($this->requestException(503));

        $this->assertTrue($site->fresh()->is_connected, '5xx is transient and must not disconnect.');
        Queue::assertNotPushed(SendNotificationJob::class);
    }

    public function test_rate_limit_and_timeout_status_are_treated_as_transient(): void
    {
        foreach ([408, 429] as $status) {
            $site = Site::factory()->create(['is_connected' => true]);
            (new SyncWordPressSite($site))->failed($this->requestException($status));
            $this->assertTrue($site->fresh()->is_connected, "HTTP {$status} must be transient.");
        }
    }

    public function test_genuine_auth_4xx_failure_disconnects_and_notifies(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        (new SyncWordPressSite($site))->failed($this->requestException(401));

        $this->assertFalse($site->fresh()->is_connected, '401 is a genuine disconnect.');

        Queue::assertPushed(
            SendNotificationJob::class,
            fn (SendNotificationJob $job) => $job->event === 'site_disconnected' && $job->severity === 'critical',
        );
    }

    public function test_wordpress_api_exception_4xx_disconnects(): void
    {
        $site = Site::factory()->create(['is_connected' => true]);

        (new SyncWordPressSite($site))->failed(new WordPressApiException('Cloudflare blocked', site: $site, httpStatus: 403));

        $this->assertFalse($site->fresh()->is_connected);
    }

    public function test_already_disconnected_site_is_not_notified_again(): void
    {
        $site = Site::factory()->create(['is_connected' => false]);

        (new SyncWordPressSite($site))->failed($this->requestException(403));

        Queue::assertNotPushed(SendNotificationJob::class);
    }
}
