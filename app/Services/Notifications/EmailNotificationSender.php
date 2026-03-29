<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationChannel;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class EmailNotificationSender
{
    public static function send(
        NotificationChannel $channel,
        string $mailableClass,
        array $mailableArgs = []
    ): array {
        $address = $channel->getDecryptedConfig()['address'] ?? null;
        if (! $address) {
            return ['success' => false, 'response_code' => null, 'error' => 'No email address configured'];
        }

        try {
            $mailable = new $mailableClass(...$mailableArgs);
            Mail::to($address)->queue($mailable);

            return ['success' => true, 'response_code' => null, 'error' => null];
        } catch (TransportExceptionInterface|\RuntimeException $e) {
            return ['success' => false, 'response_code' => null, 'error' => $e->getMessage()];
        }
    }
}
