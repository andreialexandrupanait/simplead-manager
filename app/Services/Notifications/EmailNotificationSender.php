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

            // P1-22: send synchronously (we are already inside the queued
            // SendNotificationJob) so an SMTP/transport failure surfaces here and
            // marks the log `failed`. Mail::queue() returned instantly regardless
            // of delivery, so genuine send failures were invisible and never
            // escalated.
            Mail::to($address)->send($mailable);

            return ['success' => true, 'response_code' => null, 'error' => null];
        } catch (TransportExceptionInterface|\RuntimeException $e) {
            return ['success' => false, 'response_code' => null, 'error' => $e->getMessage()];
        }
    }
}
