<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $site_id
 * @property int|null $user_id
 * @property string $type
 * @property string $severity
 * @property string $title
 * @property string|null $description
 * @property array|null $metadata
 * @property string|null $icon
 * @property string|null $url
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property-read \App\Models\Site|null $site
 * @property-read \App\Models\User|null $user
 */
class ActivityLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'user_id',
        'type',
        'severity',
        'title',
        'description',
        'metadata',
        'icon',
        'url',
        'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeOfSeverity(Builder $query, string $severity): Builder
    {
        return $query->where('severity', $severity);
    }

    public function scopeRecent(Builder $query, int $limit = 15): Builder
    {
        return $query->orderByDesc('created_at')->limit($limit);
    }
}
