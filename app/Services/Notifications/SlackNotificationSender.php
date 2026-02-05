<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Http;

class SlackNotificationSender
{
    public static function send(
        NotificationChannel $channel,
        string $title,
        string $message,
        array $fields = [],
        string $severity = 'warning'
    ): array {
        $webhookUrl = $channel->config['webhook_url'] ?? null;
        if (!$webhookUrl) {
            return ['success' => false, 'response_code' => null, 'error' => 'No webhook URL configured'];
        }

        $color = match ($severity) {
            'critical' => '#DC2626',
            'warning' => '#EAB308',
            'success' => '#16A34A',
            default => '#6B7280',
        };

        $slackFields = array_map(fn ($f) => [
            'title' => $f['title'] ?? $f['name'] ?? '',
            'value' => (string) ($f['value'] ?? ''),
            'short' => $f['short'] ?? true,
        ], $fields);

        try {
            $response = Http::post($webhookUrl, [
                'attachments' => [[
                    'color' => $color,
                    'title' => $title,
                    'text' => $message,
                    'fields' => $slackFields,
                    'ts' => now()->timestamp,
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
