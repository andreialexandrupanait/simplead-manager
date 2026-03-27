<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property string $name
 * @property string $type
 * @property array|null $config
 * @property array|null $event_subscriptions
 * @property bool $is_default
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $last_error_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, NotificationLog> $logs
 */
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
        'config' => 'encrypted:array',
        'is_default' => 'boolean',
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
        'event_subscriptions' => 'array',
        'last_error_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updated(fn (self $model) => Cache::forget("notification_channel:{$model->id}:config"));
        static::deleted(fn (self $model) => Cache::forget("notification_channel:{$model->id}:config"));
    }

    public function getDecryptedConfig(): array
    {
        return Cache::remember(
            "notification_channel:{$this->id}:config",
            600,
            fn () => $this->config ?? []
        );
    }

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
