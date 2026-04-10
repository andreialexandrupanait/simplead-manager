<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $site_id
 * @property int $score
 * @property int $critical_count
 * @property int $high_count
 * @property int $medium_count
 * @property int $low_count
 * @property int $info_count
 * @property int|null $scan_duration
 * @property int $pages_crawled
 * @property string|null $seo_plugin
 * @property string|null $seo_plugin_version
 * @property array|null $data
 * @property \Illuminate\Support\Carbon|null $scanned_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\SeoIssue> $issues
 * @property-read string $score_color
 * @property-read string $score_label
 * @property-read int $total_issues
 */
class SeoAudit extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'score',
        'critical_count',
        'high_count',
        'medium_count',
        'low_count',
        'info_count',
        'scan_duration',
        'pages_crawled',
        'seo_plugin',
        'seo_plugin_version',
        'data',
        'scanned_at',
    ];

    protected $casts = [
        'score' => 'integer',
        'critical_count' => 'integer',
        'high_count' => 'integer',
        'medium_count' => 'integer',
        'low_count' => 'integer',
        'info_count' => 'integer',
        'scan_duration' => 'integer',
        'pages_crawled' => 'integer',
        'data' => 'array',
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
        return $this->hasMany(SeoIssue::class);
    }

    public static function scoreColor(int $score): string
    {
        if ($score >= 80) {
            return 'green';
        }
        if ($score >= 50) {
            return 'yellow';
        }

        return 'red';
    }

    public function getScoreColorAttribute(): string
    {
        return static::scoreColor($this->score);
    }

    public function getScoreLabelAttribute(): string
    {
        if ($this->score >= 90) {
            return 'Excellent';
        }
        if ($this->score >= 80) {
            return 'Good';
        }
        if ($this->score >= 50) {
            return 'Needs Attention';
        }

        return 'Critical';
    }

    public function getTotalIssuesAttribute(): int
    {
        return $this->critical_count + $this->high_count + $this->medium_count + $this->low_count + $this->info_count;
    }
}
