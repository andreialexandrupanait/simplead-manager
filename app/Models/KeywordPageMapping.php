<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property int $tracked_keyword_id
 * @property string $url
 * @property string $source
 * @property int $clicks
 * @property int $impressions
 * @property float|null $avg_position
 * @property \Illuminate\Support\Carbon|null $last_seen_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Site|null $site
 * @property-read TrackedKeyword|null $trackedKeyword
 */
class KeywordPageMapping extends Model
{
    protected $fillable = [
        'site_id',
        'tracked_keyword_id',
        'url',
        'source',
        'clicks',
        'impressions',
        'avg_position',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'last_seen_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function trackedKeyword(): BelongsTo
    {
        return $this->belongsTo(TrackedKeyword::class);
    }
}
