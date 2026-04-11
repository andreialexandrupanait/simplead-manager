<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property string $rule_type
 * @property array $threshold
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $last_triggered_at
 * @property int $cooldown_minutes
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Site|null $site
 */
class SeoAlertRule extends Model
{
    public const TYPE_POSITION_DROP = 'position_drop';

    public const TYPE_TRAFFIC_DROP = 'traffic_drop';

    public const TYPE_INDEXING_CHANGE = 'indexing_change';

    public const TYPE_SCORE_DROP = 'score_drop';

    public const TYPE_PAGE_ERROR = 'page_error';

    public const TYPE_CWV_REGRESSION = 'cwv_regression';

    public const TYPES = [
        self::TYPE_POSITION_DROP,
        self::TYPE_TRAFFIC_DROP,
        self::TYPE_INDEXING_CHANGE,
        self::TYPE_SCORE_DROP,
        self::TYPE_PAGE_ERROR,
        self::TYPE_CWV_REGRESSION,
    ];

    protected $fillable = [
        'site_id',
        'rule_type',
        'threshold',
        'is_active',
        'last_triggered_at',
        'cooldown_minutes',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'array',
            'is_active' => 'boolean',
            'last_triggered_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('rule_type', $type);
    }

    public function canTrigger(): bool
    {
        if (! $this->last_triggered_at) {
            return true;
        }

        return $this->last_triggered_at->addMinutes($this->cooldown_minutes)->isPast();
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            self::TYPE_POSITION_DROP => 'Position Drop',
            self::TYPE_TRAFFIC_DROP => 'Traffic Drop',
            self::TYPE_INDEXING_CHANGE => 'Indexing Change',
            self::TYPE_SCORE_DROP => 'SEO Score Drop',
            self::TYPE_PAGE_ERROR => 'Page Error',
            self::TYPE_CWV_REGRESSION => 'CWV Regression',
            default => $type,
        };
    }

    public static function defaultThreshold(string $type): array
    {
        return match ($type) {
            self::TYPE_POSITION_DROP => ['positions' => 5, 'min_impressions' => 10],
            self::TYPE_TRAFFIC_DROP => ['drop_percent' => 20, 'min_clicks' => 50],
            self::TYPE_INDEXING_CHANGE => ['drop_count' => 5],
            self::TYPE_SCORE_DROP => ['drop_points' => 10],
            self::TYPE_PAGE_ERROR => ['error_codes' => [500, 502, 503]],
            self::TYPE_CWV_REGRESSION => ['lcp_above' => 4.0, 'cls_above' => 0.25, 'inp_above' => 500],
            default => [],
        };
    }
}
