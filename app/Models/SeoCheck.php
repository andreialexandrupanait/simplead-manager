<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoCheck extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'homepage_title',
        'homepage_meta_description',
        'has_sitemap',
        'sitemap_url',
        'sitemap_pages_count',
        'has_robots_txt',
        'robots_txt_issues',
        'has_og_tags',
        'has_twitter_cards',
        'has_schema_markup',
        'has_canonical',
        'has_h1',
        'heading_hierarchy_ok',
        'indexability_issues',
        'score',
        'checked_at',
    ];

    protected $casts = [
        'checked_at' => 'datetime',
        'has_sitemap' => 'boolean',
        'has_robots_txt' => 'boolean',
        'has_og_tags' => 'boolean',
        'has_twitter_cards' => 'boolean',
        'has_schema_markup' => 'boolean',
        'has_canonical' => 'boolean',
        'has_h1' => 'boolean',
        'heading_hierarchy_ok' => 'boolean',
        'robots_txt_issues' => 'array',
        'indexability_issues' => 'array',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
