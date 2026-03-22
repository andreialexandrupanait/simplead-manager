<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReportRecommendation extends Model
{
    protected $fillable = [
        'report_id',
        'site_id',
        'priority',
        'category',
        'title',
        'description',
        'is_auto_generated',
        'is_included',
        'sort_order',
    ];

    protected $casts = [
        'is_auto_generated' => 'boolean',
        'is_included' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeIncluded(Builder $query): Builder
    {
        return $query->where('is_included', true);
    }

    public function scopeDrafts(Builder $query): Builder
    {
        return $query->whereNull('report_id');
    }

    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where('site_id', $siteId);
    }
}
