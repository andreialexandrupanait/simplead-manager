<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $report_id
 * @property int $site_id
 * @property string $priority
 * @property string $category
 * @property string $title
 * @property string $description
 * @property bool $is_auto_generated
 * @property bool $is_included
 * @property int $sort_order
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Report|null $report
 * @property-read \App\Models\Site|null $site
 */
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

    /**
     * P3-22: link ONLY the captured draft ids that are still unlinked to a report.
     *
     * Scoping to a captured id set (instead of "every unlinked draft for the
     * site") plus the whereNull('report_id') guard keeps two reports generating
     * concurrently for the same site from stealing each other's recommendations.
     *
     * @param  array<int, int>  $draftIds
     * @return int rows linked
     */
    public static function linkDraftsToReport(array $draftIds, int $reportId): int
    {
        if ($draftIds === []) {
            return 0;
        }

        return static::whereIn('id', $draftIds)
            ->whereNull('report_id')
            ->update(['report_id' => $reportId]);
    }
}
