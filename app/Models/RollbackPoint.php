<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $site_id
 * @property string $type
 * @property string $slug
 * @property string $from_version
 * @property string $to_version
 * @property string|null $backup_reference
 * @property string $status
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property-read \App\Models\Site|null $site
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static> available()
 */
class RollbackPoint extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'type',
        'slug',
        'from_version',
        'to_version',
        'backup_reference',
        'status',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'available')->where('expires_at', '>', now());
    }
}
