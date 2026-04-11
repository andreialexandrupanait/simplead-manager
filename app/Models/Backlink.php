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
 * @property string|null $page_title
 * @property string|null $context_text
 * @property int|null $outbound_links_count
 * @property string|null $link_position
 * @property string|null $anchor_type
 * @property \Illuminate\Support\Carbon|null $last_verified_at
 * @property bool $is_alive
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Site|null $site
 * @property-read string $spam_label
 * @property-read string $spam_color
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
        'page_title',
        'context_text',
        'outbound_links_count',
        'link_position',
        'anchor_type',
        'last_verified_at',
        'is_alive',
    ];

    protected function casts(): array
    {
        return [
            'is_nofollow' => 'boolean',
            'is_alive' => 'boolean',
            'first_seen_at' => 'date',
            'last_seen_at' => 'date',
            'lost_at' => 'date',
            'last_verified_at' => 'datetime',
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

    public function scopeAlive(Builder $query): Builder
    {
        return $query->where('is_alive', true);
    }

    public function scopeClean(Builder $query): Builder
    {
        return $query->where(fn ($q) => $q->whereNull('spam_score')->orWhere('spam_score', '<', 30));
    }

    public function scopeSuspicious(Builder $query): Builder
    {
        return $query->whereBetween('spam_score', [30, 59]);
    }

    public function scopeToxic(Builder $query): Builder
    {
        return $query->where('spam_score', '>=', 60);
    }

    public function isActive(): bool
    {
        return $this->lost_at === null;
    }

    public function getSpamLabelAttribute(): string
    {
        $score = $this->spam_score ?? 0;

        return match (true) {
            $score >= 60 => 'Toxic',
            $score >= 30 => 'Suspicious',
            default => 'Clean',
        };
    }

    public function getSpamColorAttribute(): string
    {
        $score = $this->spam_score ?? 0;

        return match (true) {
            $score >= 60 => 'red',
            $score >= 30 => 'yellow',
            default => 'green',
        };
    }
}
