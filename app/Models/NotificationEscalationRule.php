<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $source_channel_id
 * @property int $escalation_channel_id
 * @property int $delay_minutes
 * @property string $severity
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read NotificationChannel $sourceChannel
 * @property-read NotificationChannel $escalationChannel
 */
class NotificationEscalationRule extends Model
{
    protected $fillable = [
        'source_channel_id',
        'escalation_channel_id',
        'delay_minutes',
        'severity',
        'is_active',
    ];

    protected $casts = [
        'delay_minutes' => 'integer',
        'is_active' => 'boolean',
    ];

    public function sourceChannel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'source_channel_id');
    }

    public function escalationChannel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'escalation_channel_id');
    }
}
