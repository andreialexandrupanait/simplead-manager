<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_crawl_id
 * @property string $url
 * @property int|null $status_code
 * @property string|null $content_type
 * @property int|null $response_time_ms
 * @property int|null $content_length
 * @property int $depth
 * @property string|null $title
 * @property int $title_length
 * @property string|null $meta_description
 * @property int $meta_desc_length
 * @property string|null $canonical_url
 * @property bool $canonical_self_ref
 * @property string|null $meta_robots
 * @property string|null $x_robots_tag
 * @property array|null $h1_tags
 * @property int $h1_count
 * @property int $h2_count
 * @property int $h3_count
 * @property int $word_count
 * @property float|null $readability_score
 * @property int $internal_links_count
 * @property int $external_links_count
 * @property array|null $internal_links
 * @property array|null $external_links
 * @property int $images_count
 * @property int $images_without_alt
 * @property array|null $structured_data_types
 * @property array|null $hreflang
 * @property string|null $og_title
 * @property string|null $og_description
 * @property string|null $og_image
 * @property string|null $redirect_url
 * @property int|null $redirect_status_code
 * @property array|null $issues
 * @property \Illuminate\Support\Carbon|null $crawled_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\SiteCrawl|null $siteCrawl
 */
class CrawledPage extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_crawl_id',
        'url',
        'status_code',
        'content_type',
        'response_time_ms',
        'content_length',
        'depth',
        'title',
        'title_length',
        'meta_description',
        'meta_desc_length',
        'canonical_url',
        'canonical_self_ref',
        'meta_robots',
        'x_robots_tag',
        'h1_tags',
        'h1_count',
        'h2_count',
        'h3_count',
        'word_count',
        'readability_score',
        'internal_links_count',
        'external_links_count',
        'internal_links',
        'external_links',
        'images',
        'images_count',
        'images_without_alt',
        'scripts',
        'stylesheets',
        'is_https',
        'has_mixed_content',
        'structured_data_types',
        'hreflang',
        'og_title',
        'og_description',
        'og_image',
        'redirect_url',
        'redirect_status_code',
        'issues',
        'crawled_at',
    ];

    protected $casts = [
        'status_code' => 'integer',
        'response_time_ms' => 'integer',
        'content_length' => 'integer',
        'depth' => 'integer',
        'title_length' => 'integer',
        'meta_desc_length' => 'integer',
        'canonical_self_ref' => 'boolean',
        'h1_tags' => 'array',
        'h1_count' => 'integer',
        'h2_count' => 'integer',
        'h3_count' => 'integer',
        'word_count' => 'integer',
        'readability_score' => 'float',
        'internal_links_count' => 'integer',
        'external_links_count' => 'integer',
        'internal_links' => 'array',
        'external_links' => 'array',
        'images' => 'array',
        'images_count' => 'integer',
        'images_without_alt' => 'integer',
        'scripts' => 'array',
        'stylesheets' => 'array',
        'is_https' => 'boolean',
        'has_mixed_content' => 'boolean',
        'structured_data_types' => 'array',
        'hreflang' => 'array',
        'redirect_status_code' => 'integer',
        'issues' => 'array',
        'crawled_at' => 'datetime',
    ];

    public function siteCrawl(): BelongsTo
    {
        return $this->belongsTo(SiteCrawl::class);
    }

    public function hasIssues(): bool
    {
        return ! empty($this->issues);
    }

    public function getIssueCountAttribute(): int
    {
        return count($this->issues ?? []);
    }

    public function isRedirect(): bool
    {
        return $this->status_code !== null && $this->status_code >= 300 && $this->status_code < 400;
    }

    public function isError(): bool
    {
        return $this->status_code !== null && $this->status_code >= 400;
    }

    public function isSuccess(): bool
    {
        return $this->status_code !== null && $this->status_code >= 200 && $this->status_code < 300;
    }
}
