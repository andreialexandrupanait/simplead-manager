<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SecurityScan extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'score',
        'critical_count',
        'high_count',
        'medium_count',
        'low_count',
        'scan_duration',
        'scanned_at',
    ];

    protected $casts = [
        'score' => 'integer',
        'critical_count' => 'integer',
        'high_count' => 'integer',
        'medium_count' => 'integer',
        'low_count' => 'integer',
        'scan_duration' => 'integer',
        'scanned_at' => 'datetime',
    ];

    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function issues(): HasMany
    {
        return $this->hasMany(SecurityIssue::class);
    }

    public static function scoreColor(int $score): string
    {
        if ($score >= 80) return 'green';
        if ($score >= 50) return 'yellow';
        return 'red';
    }

    public function getScoreColorAttribute(): string
    {
        return static::scoreColor($this->score);
    }

    public function getScoreLabelAttribute(): string
    {
        if ($this->score >= 90) return 'Excellent';
        if ($this->score >= 80) return 'Good';
        if ($this->score >= 50) return 'Needs Attention';
        return 'Critical';
    }

    public function getTotalIssuesAttribute(): int
    {
        return $this->critical_count + $this->high_count + $this->medium_count + $this->low_count;
    }
}
