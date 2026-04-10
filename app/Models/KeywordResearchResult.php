<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class KeywordResearchResult extends Model
{
    protected $fillable = [
        'user_id',
        'site_id',
        'seed_keyword',
        'language',
        'country',
        'suggestions',
        'gsc_data',
        'clusters',
    ];

    protected $casts = [
        'suggestions' => 'array',
        'gsc_data' => 'array',
        'clusters' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
