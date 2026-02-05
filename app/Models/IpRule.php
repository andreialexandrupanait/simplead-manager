<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IpRule extends Model
{
    protected $fillable = [
        'site_id',
        'ip_address',
        'type',
        'reason',
        'expires_at',
        'created_by',
        'hits_count',
        'last_hit_at',
        'is_synced',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'is_synced' => 'boolean',
        'hits_count' => 'integer',
        'last_hit_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function blockedRequests(): HasMany
    {
        return $this->hasMany(BlockedRequest::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')
              ->orWhere('expires_at', '>', now());
        });
    }

    public function scopeBlocked(Builder $query): Builder
    {
        return $query->where('type', 'block');
    }

    public function scopeAllowed(Builder $query): Builder
    {
        return $query->where('type', 'allow');
    }

    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where(function ($q) use ($siteId) {
            $q->where('site_id', $siteId)
              ->orWhereNull('site_id');
        });
    }
}
