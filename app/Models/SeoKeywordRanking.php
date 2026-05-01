<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoKeywordRanking extends Model
{
    protected $fillable = [
        'site_id', 'keyword', 'keyword_hash', 'url',
        'position', 'clicks', 'impressions', 'ctr',
        'recorded_date', 'is_tracked',
    ];

    protected function casts(): array
    {
        return [
            'position' => 'float',
            'ctr' => 'float',
            'recorded_date' => 'date',
            'is_tracked' => 'boolean',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeTracked(Builder $query): Builder
    {
        return $query->where('is_tracked', true);
    }

    public function scopeForKeyword(Builder $query, string $keyword): Builder
    {
        return $query->where('keyword_hash', md5(mb_strtolower(trim($keyword))));
    }
}
