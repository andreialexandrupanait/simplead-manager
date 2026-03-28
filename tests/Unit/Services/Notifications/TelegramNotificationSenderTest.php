<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Notifications;

use App\Models\NotificationChannel;
use App\Services\Notifications\TelegramNotificationSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class TelegramNotificationSenderTest extends TestCase
{
    use RefreshDatabase;

    private function createChannel(array $config = []): NotificationChannel
    {
        return NotificationChannel::factory()->telegram()->create([
            'config' => array_merge([
                'bot_token' => encrypt('123456:ABC-DEF'),
                'chat_id' => '-100123456789',
            ], $config),
        ]);
    }

    #[Test]
    public function sends_to_telegram_api(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => []])]);

        $result = TelegramNotificationSender::send(
            $this->createChannel(),
            'Alert',
            'Site is down',
            [['title' => 'URL', 'value' => 'https://example.com']],
            'critical'
        );

        $this->assertTrue($result['success']);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'api.telegram.org'));
    }

    #[Test]
    public function returns_error_without_bot_token(): void
    {
        $result = TelegramNotificationSender::send(
            $this->createChannel(['bot_token' => null]),
            'Test', 'message'
        );

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('not configured', $result['error']);
    }

    #[Test]
    public function includes_fields_in_message(): void
    {
        Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);

        TelegramNotificationSender::send(
            $this->createChannel(),
            'Test',
            'Body',
            [['title' => 'Status', 'value' => 'Down']],
        );

        Http::assertSent(function ($request) {
            $body = $request->data();

            return str_contains($body['text'] ?? '', 'Status:') && str_contains($body['text'] ?? '', 'Down');
        });
    }
}
