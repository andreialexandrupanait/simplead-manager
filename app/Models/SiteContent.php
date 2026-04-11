<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SiteContent extends Model
{
    protected $fillable = [
        'site_id', 'wp_post_id', 'title', 'type', 'status', 'url',
        'word_count', 'published_at', 'modified_at', 'author_name',
        'days_since_modified', 'is_stale', 'checked_at',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'modified_at' => 'datetime',
        'checked_at' => 'datetime',
        'is_stale' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeStale(Builder $query, int $days = 180): Builder
    {
        return $query->where('days_since_modified', '>', $days);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', 'publish');
    }

    public function scopePosts(Builder $query): Builder
    {
        return $query->where('type', 'post');
    }

    public function scopePages(Builder $query): Builder
    {
        return $query->where('type', 'page');
    }
}
