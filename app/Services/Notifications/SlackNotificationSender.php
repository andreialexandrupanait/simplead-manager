<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use Illuminate\Http\Client\RequestException;
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
        $webhookUrl = $channel->getDecryptedConfig()['webhook_url'] ?? null;
        if (! $webhookUrl) {
            return ['success' => false, 'response_code' => null, 'error' => 'No webhook URL configured'];
        }

        $color = match ($severity) {
            'critical' => '#DC2626',
            'warning' => '#EAB308',
            'success' => '#16A34A',
            default => '#6B7280',
        };

        $parts = array_filter([$title, $message], static fn (string $s): bool => $s !== '');
        $text = implode("\n", $parts);

        if (! empty($fields)) {
            $rendered = [];
            foreach ($fields as $f) {
                $label = $f['title'] ?? $f['name'] ?? '';
                $value = (string) ($f['value'] ?? '');
                if ($value === '') {
                    continue;
                }
                $rendered[] = $label !== '' ? "*{$label}:* {$value}" : $value;
            }
            if ($rendered !== []) {
                $text .= "\n".implode(' · ', $rendered);
            }
        }

        try {
            $response = Http::timeout(5)->post($webhookUrl, [
                'attachments' => [[
                    'color' => $color,
                    'text' => $text,
                    'mrkdwn_in' => ['text'],
                ]],
            ]);

            return [
                'success' => $response->successful(),
                'response_code' => $response->status(),
                'error' => $response->successful() ? null : $response->body(),
            ];
        } catch (RequestException|\RuntimeException $e) {
            return ['success' => false, 'response_code' => null, 'error' => $e->getMessage()];
        }
    }
}
