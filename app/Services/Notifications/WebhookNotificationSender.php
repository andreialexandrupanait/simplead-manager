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
        $config = $channel->getDecryptedConfig();
        $url = $config['url'] ?? null;
        if (!$url) {
            return ['success' => false, 'response_code' => null, 'error' => 'No webhook URL configured'];
        }

        $method = strtolower($config['method'] ?? 'POST');
        $headers = $config['headers'] ?? [];

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

        // Add HMAC signature if signing secret is configured
        $signingSecret = $config['signing_secret'] ?? null;
        if ($signingSecret) {
            $body = json_encode($data);
            $headers['X-Signature'] = hash_hmac('sha256', $body, $signingSecret);
        }

        try {
            $response = Http::timeout(10)->withHeaders($headers)->$method($url, $data);

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
