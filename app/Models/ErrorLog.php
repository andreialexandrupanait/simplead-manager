<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ErrorLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'error_hash',
        'level',
        'message',
        'file_path',
        'line_number',
        'stack_trace',
        'context',
        'count',
        'first_seen_at',
        'last_seen_at',
        'is_resolved',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'context' => 'array',
        'is_resolved' => 'boolean',
        'count' => 'integer',
        'line_number' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeUnresolved($query)
    {
        return $query->where('is_resolved', false);
    }

    public function scopeFatal($query)
    {
        return $query->where('level', 'fatal');
    }

    public function scopeLevel($query, string $level)
    {
        return $query->where('level', $level);
    }

    public function getLevelColorAttribute(): string
    {
        return match ($this->level) {
            'fatal' => 'red',
            'error' => 'orange',
            'warning' => 'yellow',
            'notice' => 'blue',
            'deprecated' => 'gray',
            default => 'gray',
        };
    }
}
