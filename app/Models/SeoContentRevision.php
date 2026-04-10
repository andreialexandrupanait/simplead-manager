<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeoContentRevision extends Model
{
    protected $fillable = [
        'seo_content_id',
        'content',
        'meta_description',
        'source',
        'generation_params',
    ];

    protected $casts = [
        'generation_params' => 'array',
    ];

    public function seoContent(): BelongsTo
    {
        return $this->belongsTo(SeoContent::class);
    }
}
