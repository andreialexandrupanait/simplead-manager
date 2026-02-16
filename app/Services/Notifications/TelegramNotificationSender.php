<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;

class TelegramNotificationSender
{
    public static function send(
        NotificationChannel $channel,
        string $title,
        string $message,
        array $fields = [],
        string $severity = 'warning'
    ): array {
        $config = $channel->getDecryptedConfig();
        $botToken = $config['bot_token'] ?? null;
        $chatId = $config['chat_id'] ?? null;

        if (!$botToken || !$chatId) {
            return ['success' => false, 'response_code' => null, 'error' => 'Bot token or chat ID not configured'];
        }

        try {
            $botToken = decrypt($botToken);
        } catch (\Exception $e) {
            return ['success' => false, 'response_code' => null, 'error' => 'Failed to decrypt bot token'];
        }

        $emoji = match ($severity) {
            'critical' => "\xF0\x9F\x94\xB4", // red circle
            'warning' => "\xF0\x9F\x9F\xA1",  // yellow circle
            'success' => "\xF0\x9F\x9F\xA2",  // green circle
            default => "\xF0\x9F\x94\xB5",     // blue circle
        };

        $text = "{$emoji} *{$title}*\n\n{$message}";

        if (!empty($fields)) {
            $text .= "\n";
            foreach ($fields as $field) {
                $name = $field['title'] ?? $field['name'] ?? '';
                $value = $field['value'] ?? '';
                $text .= "\n*{$name}:* {$value}";
            }
        }

        try {
            $response = Http::post("https://api.telegram.org/bot{$botToken}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $text,
                'parse_mode' => 'Markdown',
                'disable_web_page_preview' => true,
            ]);

            return [
                'success' => $response->successful(),
                'response_code' => $response->status(),
                'error' => $response->successful() ? null : ($response->json('description') ?? $response->body()),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'response_code' => null, 'error' => $e->getMessage()];
        }
    }
}
