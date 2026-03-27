<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tracked_keyword_id
 * @property \Illuminate\Support\Carbon $date
 * @property float|null $position
 * @property int $clicks
 * @property int $impressions
 * @property float $ctr
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read TrackedKeyword|null $trackedKeyword
 */
class KeywordPosition extends Model
{
    use HasFactory;

    protected $fillable = ['tracked_keyword_id', 'date', 'position', 'clicks', 'impressions', 'ctr'];

    protected $casts = [
        'date' => 'date',
        'position' => 'float',
        'ctr' => 'float',
    ];

    public function trackedKeyword(): BelongsTo
    {
        return $this->belongsTo(TrackedKeyword::class);
    }
}
