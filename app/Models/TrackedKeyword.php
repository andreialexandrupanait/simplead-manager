<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $site_id
 * @property string $keyword
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read Site|null $site
 * @property-read \Illuminate\Database\Eloquent\Collection<int, KeywordPosition> $positions
 */
class TrackedKeyword extends Model
{
    use HasFactory;

    protected $fillable = ['site_id', 'keyword'];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function positions(): HasMany
    {
        return $this->hasMany(KeywordPosition::class);
    }
}
