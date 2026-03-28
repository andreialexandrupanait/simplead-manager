<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $notification_channel_id
 * @property int|null $site_id
 * @property string $event
 * @property string $channel_type
 * @property string $status
 * @property string|null $message
 * @property string|null $error_message
 * @property array|null $metadata
 * @property int|null $response_code
 * @property string|null $ack_token
 * @property \Illuminate\Support\Carbon|null $acknowledged_at
 * @property string|null $severity
 * @property bool $escalated
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read NotificationChannel|null $channel
 * @property-read Site|null $site
 */
class NotificationLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'notification_channel_id',
        'site_id',
        'event',
        'channel_type',
        'status',
        'message',
        'error_message',
        'metadata',
        'response_code',
        'severity',
        'ack_token',
        'acknowledged_at',
        'escalated',
    ];

    protected $casts = [
        'metadata' => 'array',
        'acknowledged_at' => 'datetime',
        'escalated' => 'boolean',
    ];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(NotificationChannel::class, 'notification_channel_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
