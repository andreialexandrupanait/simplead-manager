<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DnsMonitor extends Model
{
    protected $fillable = [
        'site_id', 'domain', 'is_active', 'interval_minutes',
        'last_checked_at', 'next_check_at', 'current_records',
        'previous_records', 'has_changes', 'dkim_selectors',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'has_changes' => 'boolean',
        'current_records' => 'array',
        'previous_records' => 'array',
        'dkim_selectors' => 'array',
        'last_checked_at' => 'datetime',
        'next_check_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function changes(): HasMany
    {
        return $this->hasMany(DnsChange::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('next_check_at')->orWhere('next_check_at', '<=', now());
        });
    }
}
