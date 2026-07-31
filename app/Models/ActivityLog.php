<?php

declare(strict_types=1);

namespace App\Models;

use App\Casts\SafeBackedEnum;
use App\Enums\ActivitySeverity;
use App\Enums\ActivityType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $site_id
 * @property int|null $user_id
 * @property \App\Enums\ActivityType|null $type
 * @property \App\Enums\ActivitySeverity|null $severity
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
        'type' => SafeBackedEnum::class.':'.ActivityType::class,
        'severity' => SafeBackedEnum::class.':'.ActivitySeverity::class,
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Where this event actually happened.
     *
     * Most helpers in {@see \App\Services\ActivityLogger} pass an explicit
     * `url:`, but the bare `log()` calls do not — about a fifth of rows have no
     * destination at all. Rather than leave those inert, fall back to the site
     * the event belongs to, which is the answer the reader wanted anyway.
     *
     * Null means genuinely nowhere to go (an auth or settings event with no
     * site); callers must treat the row as non-clickable rather than link to a
     * dead href.
     */
    public function getTargetUrlAttribute(): ?string
    {
        if (filled($this->url)) {
            return $this->url;
        }

        return $this->site ? route('sites.overview', $this->site) : null;
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
