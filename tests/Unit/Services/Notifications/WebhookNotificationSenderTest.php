<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Notifications;

use App\Models\NotificationChannel;
use App\Models\Site;
use App\Models\SiteHealthState;
use App\Services\Notifications\WebhookNotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class WebhookNotificationSenderTest extends TestCase
{
    use RefreshDatabase;

    private function createChannel(array $config = []): NotificationChannel
    {
        return NotificationChannel::factory()->create([
            'type' => 'webhook',
            'config' => array_merge([
                'url' => 'https://api.example.com/webhook',
                'method' => 'POST',
                'headers' => [],
            ], $config),
        ]);
    }

    #[Test]
    public function sends_post_request(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);

        $result = WebhookNotificationSender::send(
            $this->createChannel(),
            'site_down',
            null,
            ['title' => 'Alert', 'message' => 'Site is down']
        );

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($r) => $r->method() === 'POST');
    }

    #[Test]
    public function includes_site_data_in_payload(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);

        $site = Site::factory()->create(['name' => 'Test Site', 'url' => 'https://test.com']);
        SiteHealthState::create(['site_id' => $site->id, 'circuit_state' => 'closed']);

        WebhookNotificationSender::send(
            $this->createChannel(),
            'site_down',
            $site,
            ['title' => 'Down']
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['site']['name'] ?? '') === 'Test Site'
                && ($body['event'] ?? '') === 'site_down';
        });
    }

    #[Test]
    public function returns_error_without_url(): void
    {
        $result = WebhookNotificationSender::send(
            $this->createChannel(['url' => null]),
            'test',
            null,
            []
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No webhook URL', $result['error']);
    }

    #[Test]
    public function adds_hmac_signature_when_secret_configured(): void
    {
        Http::fake(['api.example.com/*' => Http::response(['ok' => true])]);

        WebhookNotificationSender::send(
            $this->createChannel(['signing_secret' => 'my-secret']),
            'test',
            null,
            ['data' => 'value']
        );

        Http::assertSent(function ($request) {
            return $request->hasHeader('X-Signature');
        });
    }

    #[Test]
    public function handles_api_failure(): void
    {
        Http::fake(['api.example.com/*' => Http::response('Server Error', 500)]);

        $result = WebhookNotificationSender::send(
            $this->createChannel(),
            'test',
            null,
            []
        );

        $this->assertFalse($result['success']);
        $this->assertEquals(500, $result['response_code']);
    }
}
