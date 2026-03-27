<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property int|null $security_scan_id
 * @property string $category
 * @property string $type
 * @property string $severity
 * @property string $title
 * @property string|null $description
 * @property string|null $recommendation
 * @property bool $is_fixed
 * @property bool $is_ignored
 * @property \Illuminate\Support\Carbon|null $first_detected_at
 * @property \Illuminate\Support\Carbon|null $fixed_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 * @property-read \App\Models\SecurityScan|null $securityScan
 */
class SecurityIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'security_scan_id',
        'category',
        'type',
        'severity',
        'title',
        'description',
        'recommendation',
        'is_fixed',
        'is_ignored',
        'first_detected_at',
        'fixed_at',
    ];

    protected $casts = [
        'is_fixed' => 'boolean',
        'is_ignored' => 'boolean',
        'first_detected_at' => 'datetime',
        'fixed_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function securityScan(): BelongsTo
    {
        return $this->belongsTo(SecurityScan::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_fixed', false)->where('is_ignored', false);
    }

    public function scopeSeverity(Builder $query, string $level): Builder
    {
        return $query->where('severity', $level);
    }

    public function scopeOrderBySeverity(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderByRaw("CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 ELSE 5 END ".($direction === 'desc' ? 'DESC' : 'ASC'));
    }

    public function getSeverityColorAttribute(): string
    {
        return match ($this->severity) {
            'critical' => 'red',
            'high' => 'orange',
            'medium' => 'yellow',
            'low' => 'blue',
            default => 'gray',
        };
    }

    public function getCategoryLabelAttribute(): string
    {
        return ucfirst(str_replace('_', ' ', $this->category));
    }
}
