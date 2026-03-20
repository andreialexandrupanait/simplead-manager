<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityActivityLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'event_type',
        'event_category',
        'username',
        'object_type',
        'object_name',
        'action',
        'ip_address',
        'user_agent',
        'details',
        'occurred_at',
        'created_at',
    ];

    protected $casts = [
        'details' => 'array',
        'occurred_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeForEventType(Builder $query, string $eventType): Builder
    {
        return $query->where('event_type', $eventType);
    }

    public function scopeForIp(Builder $query, string $ip): Builder
    {
        return $query->where('ip_address', $ip);
    }

    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('occurred_at', '>=', now()->subDays($days));
    }
}
