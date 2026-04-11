<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $site_id
 * @property string $competitor_url
 * @property string|null $competitor_name
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Site|null $site
 * @property-read \Illuminate\Database\Eloquent\Collection<int, CompetitorKeywordPosition> $keywordPositions
 */
class CompetitorSite extends Model
{
    protected $fillable = [
        'site_id',
        'competitor_url',
        'competitor_name',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function keywordPositions(): HasMany
    {
        return $this->hasMany(CompetitorKeywordPosition::class);
    }

    public function getDisplayNameAttribute(): string
    {
        return $this->competitor_name ?: parse_url($this->competitor_url, PHP_URL_HOST) ?: $this->competitor_url;
    }
}
