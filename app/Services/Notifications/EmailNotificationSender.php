<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Mail\Contracts\PersonalizedMailable;
use App\Models\NotificationChannel;
use App\Models\User;
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
            $user = null;

            // A channel stores an address and nothing else, so a mailable that
            // wants to greet the reader by name — or print a timestamp in their
            // timezone — has to be told who they are here. Mailables that do not
            // opt into the contract are constructed exactly as before.
            if ($mailable instanceof PersonalizedMailable) {
                $user = self::resolveUser($address);
                $mailable = $mailable->withRecipient($address, $user, $channel);
            }

            // P1-22: send synchronously (we are already inside the queued
            // SendNotificationJob) so an SMTP/transport failure surfaces here and
            // marks the log `failed`. Mail::queue() returned instantly regardless
            // of delivery, so genuine send failures were invisible and never
            // escalated.
            Mail::to($address, $user?->name)->send($mailable);

            return ['success' => true, 'response_code' => null, 'error' => null];
        } catch (TransportExceptionInterface|\RuntimeException $e) {
            return ['success' => false, 'response_code' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * The account behind a channel address, when there is one. Compared
     * case-insensitively — addresses are typed by hand into the channel form,
     * and Postgres would otherwise miss "Andrei@…" against a stored "andrei@…".
     */
    private static function resolveUser(string $address): ?User
    {
        return User::whereRaw('lower(email) = ?', [mb_strtolower(trim($address))])->first();
    }
}
