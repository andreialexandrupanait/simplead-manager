<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property \Illuminate\Support\Carbon $date
 * @property int $total_backlinks
 * @property int $referring_domains
 * @property int $new_backlinks
 * @property int $lost_backlinks
 * @property int $dofollow_count
 * @property int $nofollow_count
 * @property array $anchor_text_distribution
 * @property array $top_pages
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Site|null $site
 */
class BacklinkSnapshot extends Model
{
    protected $fillable = [
        'site_id',
        'date',
        'total_backlinks',
        'referring_domains',
        'new_backlinks',
        'lost_backlinks',
        'dofollow_count',
        'nofollow_count',
        'anchor_text_distribution',
        'top_pages',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'anchor_text_distribution' => 'array',
            'top_pages' => 'array',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
