<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;

class DiscordNotificationSender
{
    public static function send(
        NotificationChannel $channel,
        string $title,
        string $message,
        array $fields = [],
        string $severity = 'warning'
    ): array {
        $webhookUrl = $channel->getDecryptedConfig()['webhook_url'] ?? null;
        if (! $webhookUrl) {
            return ['success' => false, 'response_code' => null, 'error' => 'No webhook URL configured'];
        }

        $color = match ($severity) {
            'critical' => 0xDC2626,
            'warning' => 0xEAB308,
            'success' => 0x16A34A,
            default => 0x6B7280,
        };

        $discordFields = array_map(fn ($f) => [
            'name' => $f['title'] ?? $f['name'] ?? '',
            'value' => (string) ($f['value'] ?? ''),
            'inline' => $f['inline'] ?? true,
        ], $fields);

        try {
            $response = Http::timeout(10)->post($webhookUrl, [
                'embeds' => [[
                    'title' => $title,
                    'description' => $message,
                    'color' => $color,
                    'fields' => $discordFields,
                    'timestamp' => now()->toIso8601String(),
                ]],
            ]);

            return [
                'success' => $response->successful(),
                'response_code' => $response->status(),
                'error' => $response->successful() ? null : $response->body(),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'response_code' => null, 'error' => $e->getMessage()];
        }
    }
}
