<?php

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use App\Models\Site;
use Illuminate\Support\Facades\Http;

class WebhookNotificationSender
{
    public static function send(
        NotificationChannel $channel,
        string $event,
        ?Site $site,
        array $payload
    ): array {
        $url = $channel->config['url'] ?? null;
        if (!$url) {
            return ['success' => false, 'response_code' => null, 'error' => 'No webhook URL configured'];
        }

        $method = strtolower($channel->config['method'] ?? 'POST');
        $headers = $channel->config['headers'] ?? [];

        $data = array_merge($payload, [
            'event' => $event,
            'timestamp' => now()->toIso8601String(),
        ]);

        if ($site) {
            $data['site'] = [
                'name' => $site->name,
                'url' => $site->url,
            ];
        }

        try {
            $response = Http::withHeaders($headers)->$method($url, $data);

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
