<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
