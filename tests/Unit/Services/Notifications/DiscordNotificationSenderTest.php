<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Notifications;

use App\Models\NotificationChannel;
use App\Services\Notifications\DiscordNotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DiscordNotificationSenderTest extends TestCase
{
    use RefreshDatabase;

    private function createChannel(array $config = []): NotificationChannel
    {
        return NotificationChannel::factory()->discord()->create([
            'config' => array_merge([
                'webhook_url' => 'https://discord.com/api/webhooks/123/abc',
            ], $config),
        ]);
    }

    #[Test]
    public function sends_embed_to_discord(): void
    {
        Http::fake(['discord.com/*' => Http::response('', 204)]);

        $result = DiscordNotificationSender::send(
            $this->createChannel(),
            'Alert',
            'Server is down',
            [['title' => 'Site', 'value' => 'example.com']],
            'critical'
        );

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'discord.com'));
    }

    #[Test]
    public function returns_error_without_webhook_url(): void
    {
        $result = DiscordNotificationSender::send(
            $this->createChannel(['webhook_url' => null]),
            'Test', 'message'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('No webhook URL', $result['error']);
    }

    #[Test]
    public function maps_severity_to_color(): void
    {
        Http::fake(['discord.com/*' => Http::response('', 204)]);

        DiscordNotificationSender::send($this->createChannel(), 'Test', 'msg', [], 'warning');

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            return ($body['embeds'][0]['color'] ?? 0) === 0xEAB308;
        });
    }
}
