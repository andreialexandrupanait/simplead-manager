<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property string $source_url
 * @property string $target_url
 * @property string $source_domain
 * @property string|null $anchor_text
 * @property bool $is_nofollow
 * @property string|null $link_type
 * @property \Illuminate\Support\Carbon $first_seen_at
 * @property \Illuminate\Support\Carbon $last_seen_at
 * @property \Illuminate\Support\Carbon|null $lost_at
 * @property string $source_type
 * @property int|null $spam_score
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Site|null $site
 */
class Backlink extends Model
{
    protected $fillable = [
        'site_id',
        'source_url',
        'target_url',
        'source_domain',
        'anchor_text',
        'is_nofollow',
        'link_type',
        'first_seen_at',
        'last_seen_at',
        'lost_at',
        'source_type',
        'spam_score',
    ];

    protected function casts(): array
    {
        return [
            'is_nofollow' => 'boolean',
            'first_seen_at' => 'date',
            'last_seen_at' => 'date',
            'lost_at' => 'date',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('lost_at');
    }

    public function scopeLost(Builder $query): Builder
    {
        return $query->whereNotNull('lost_at');
    }

    public function isActive(): bool
    {
        return $this->lost_at === null;
    }
}
