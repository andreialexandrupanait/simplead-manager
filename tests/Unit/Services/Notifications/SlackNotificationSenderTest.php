<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Notifications;

use App\Models\NotificationChannel;
use App\Services\Notifications\SlackNotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SlackNotificationSenderTest extends TestCase
{
    use RefreshDatabase;

    private function createChannel(array $config = []): NotificationChannel
    {
        return NotificationChannel::factory()->slack()->create([
            'config' => array_merge([
                'webhook_url' => 'https://hooks.slack.com/services/T00/B00/xxx',
            ], $config),
        ]);
    }

    #[Test]
    public function sends_to_slack_webhook(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('ok', 200)]);

        $result = SlackNotificationSender::send(
            $this->createChannel(),
            'Site Down',
            'example.com is unreachable',
            [['title' => 'URL', 'value' => 'https://example.com']],
            'critical'
        );

        $this->assertTrue($result['success']);
        $this->assertEquals(200, $result['response_code']);
        Http::assertSentCount(1);
    }

    #[Test]
    public function returns_error_without_webhook_url(): void
    {
        $result = SlackNotificationSender::send(
            $this->createChannel(['webhook_url' => null]),
            'Test', 'message'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No webhook URL', $result['error']);
    }

    #[Test]
    public function returns_error_on_api_failure(): void
    {
        Http::fake(['hooks.slack.com/*' => Http::response('invalid_payload', 400)]);

        $result = SlackNotificationSender::send(
            $this->createChannel(),
            'Test', 'message'
        );

        $this->assertFalse($result['success']);
        $this->assertEquals(400, $result['response_code']);
    }
}
