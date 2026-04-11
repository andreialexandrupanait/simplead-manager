<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $competitor_site_id
 * @property string $keyword
 * @property float|null $position
 * @property string|null $url
 * @property \Illuminate\Support\Carbon $date
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read CompetitorSite|null $competitorSite
 */
class CompetitorKeywordPosition extends Model
{
    protected $fillable = [
        'competitor_site_id',
        'keyword',
        'position',
        'url',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'date' => 'date',
        ];
    }

    public function competitorSite(): BelongsTo
    {
        return $this->belongsTo(CompetitorSite::class);
    }
}
