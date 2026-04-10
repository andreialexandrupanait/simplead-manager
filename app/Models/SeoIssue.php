<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeoIssueCategory;
use App\Enums\SeoIssueSeverity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property int $seo_audit_id
 * @property string $category
 * @property string $severity
 * @property string $title
 * @property string|null $description
 * @property string|null $url
 * @property string|null $recommendation
 * @property array|null $meta
 * @property \Illuminate\Support\Carbon|null $resolved_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 * @property-read \App\Models\SeoAudit|null $seoAudit
 * @property-read string $severity_color
 * @property-read string $category_label
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> active()
 * @method static \Illuminate\Database\Eloquent\Builder<static> severity(string $level)
 * @method static \Illuminate\Database\Eloquent\Builder<static> orderBySeverity(string $direction = 'asc')
 */
class SeoIssue extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'seo_audit_id',
        'category',
        'severity',
        'title',
        'description',
        'url',
        'recommendation',
        'meta',
        'resolved_at',
    ];

    protected $casts = [
        'meta' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function seoAudit(): BelongsTo
    {
        return $this->belongsTo(SeoAudit::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function scopeSeverity(Builder $query, string $level): Builder
    {
        return $query->where('severity', $level);
    }

    public function scopeOrderBySeverity(Builder $query, string $direction = 'asc'): Builder
    {
        return $query->orderByRaw(
            "CASE severity WHEN 'critical' THEN 1 WHEN 'high' THEN 2 WHEN 'medium' THEN 3 WHEN 'low' THEN 4 WHEN 'info' THEN 5 ELSE 6 END ".
            ($direction === 'desc' ? 'DESC' : 'ASC')
        );
    }

    public function getSeverityColorAttribute(): string
    {
        $enum = SeoIssueSeverity::tryFrom($this->severity);

        return $enum?->color() ?? 'gray';
    }

    public function getCategoryLabelAttribute(): string
    {
        $enum = SeoIssueCategory::tryFrom($this->category);

        return $enum?->label() ?? ucfirst(str_replace('_', ' ', $this->category));
    }

    public function getCategoryIconAttribute(): string
    {
        $enum = SeoIssueCategory::tryFrom($this->category);

        return $enum?->icon() ?? 'question-mark-circle';
    }
}
