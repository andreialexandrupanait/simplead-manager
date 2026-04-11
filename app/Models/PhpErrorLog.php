<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PhpErrorLog extends Model
{
    protected $fillable = [
        'site_id', 'level', 'message', 'file', 'line', 'message_hash',
        'count', 'first_seen_at', 'last_seen_at', 'is_resolved',
    ];

    protected $casts = [
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'is_resolved' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeFatal(Builder $query): Builder
    {
        return $query->where('level', 'fatal');
    }

    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->where('is_resolved', false);
    }

    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('last_seen_at', '>=', now()->subDays($days));
    }
}
