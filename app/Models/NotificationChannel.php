<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NotificationChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'config',
        'is_default',
        'is_active',
        'last_used_at',
        'event_subscriptions',
        'last_error',
        'last_error_at',
    ];

    protected $casts = [
        'config' => 'array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'event_subscriptions' => 'array',
        'last_error_at' => 'datetime',
    ];

    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    /**
     * Check if this channel is subscribed to a given event.
     * Returns true if event_subscriptions is null (all events) or contains the event.
     */
    public function subscribedTo(string $event): bool
    {
        if ($this->event_subscriptions === null) {
            return true;
        }

        return in_array($event, $this->event_subscriptions);
    }
}
