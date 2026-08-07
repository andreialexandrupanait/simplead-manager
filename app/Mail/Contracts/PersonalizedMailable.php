<?php

declare(strict_types=1);

namespace App\Mail\Contracts;

use App\Models\NotificationChannel;
use App\Models\User;

/**
 * Implemented by mailables that want to know who they are being sent to.
 *
 * Notification channels store a bare address and nothing else, so a mailable
 * constructed from `$mailableArgs` alone cannot greet anyone by name or pick a
 * timezone. EmailNotificationSender calls this after construction when the
 * mailable opts in, which keeps the other mailables untouched.
 */
interface PersonalizedMailable
{
    /**
     * @param  string  $address  the channel's configured recipient address
     * @param  User|null  $user  the account owning that address, when one exists
     */
    public function withRecipient(string $address, ?User $user, NotificationChannel $channel): static;
}
