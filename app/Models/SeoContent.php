<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SeoContentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SeoContent extends Model
{
    protected $fillable = [
        'site_id',
        'user_id',
        'title',
        'slug',
        'status',
        'target_keyword',
        'secondary_keywords',
        'brief',
        'content',
        'meta_description',
        'tone',
        'persona',
        'ai_provider',
        'ai_model',
        'target_audience',
        'target_word_count',
        'sections',
        'seo_score_data',
        'seo_score',
        'word_count',
        'keyword_density',
        'wp_post_id',
        'published_at',
        'scheduled_at',
    ];

    protected $casts = [
        'secondary_keywords' => 'array',
        'sections' => 'array',
        'seo_score_data' => 'array',
        'seo_score' => 'integer',
        'word_count' => 'integer',
        'target_word_count' => 'integer',
        'keyword_density' => 'float',
        'published_at' => 'datetime',
        'scheduled_at' => 'datetime',
        'status' => SeoContentStatus::class,
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(SeoContentRevision::class);
    }

    public function getStatusColorAttribute(): string
    {
        return $this->status->color();
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status->label();
    }
}
