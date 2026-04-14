<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoImage extends Model
{
    protected $fillable = [
        'seo_audit_id', 'seo_page_id', 'image_url', 'image_url_hash',
        'alt_text', 'status_code', 'is_broken', 'has_alt', 'has_lazy_loading',
        'file_size_bytes', 'content_type',
    ];

    protected function casts(): array
    {
        return [
            'is_broken' => 'boolean',
            'has_alt' => 'boolean',
            'has_lazy_loading' => 'boolean',
        ];
    }

    public function audit(): BelongsTo
    {
        return $this->belongsTo(SeoAudit::class, 'seo_audit_id');
    }

    public function page(): BelongsTo
    {
        return $this->belongsTo(SeoPage::class, 'seo_page_id');
    }

    public function scopeBroken(Builder $query): Builder
    {
        return $query->where('is_broken', true);
    }

    public function scopeMissingAlt(Builder $query): Builder
    {
        return $query->where('has_alt', false);
    }
}
