<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $site_id
 * @property string $domain
 * @property bool $is_active
 * @property int $interval_minutes
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property \Illuminate\Support\Carbon|null $next_check_at
 * @property array|null $current_records
 * @property array|null $previous_records
 * @property array|null $pending_records
 * @property bool $has_changes
 * @property array|null $dkim_selectors
 * @property int $consecutive_failures
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $last_error_at
 * @property-read \App\Models\Site|null $site
 */
class DnsMonitor extends Model
{
    protected $fillable = [
        'site_id', 'domain', 'is_active', 'interval_minutes',
        'last_checked_at', 'next_check_at', 'current_records',
        'previous_records', 'pending_records', 'has_changes', 'dkim_selectors',
        'consecutive_failures', 'last_error', 'last_error_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'has_changes' => 'boolean',
        'current_records' => 'array',
        'previous_records' => 'array',
        'pending_records' => 'array',
        'dkim_selectors' => 'array',
        'last_checked_at' => 'datetime',
        'next_check_at' => 'datetime',
        'consecutive_failures' => 'integer',
        'last_error_at' => 'datetime',
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
