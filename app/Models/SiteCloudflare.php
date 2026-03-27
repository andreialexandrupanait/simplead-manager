<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $site_id
 * @property int $cloudflare_connection_id
 * @property string $zone_id
 * @property string $zone_name
 * @property string|null $plan_type
 * @property string $status
 * @property bool $is_paused
 * @property string|null $ssl_mode
 * @property string|null $cache_level
 * @property \Illuminate\Support\Carbon|null $connected_at
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $next_sync_at
 * @property int $interval_minutes
 * @property \Illuminate\Support\Carbon|null $last_sync_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 * @property-read \App\Models\CloudflareConnection|null $cloudflareConnection
 * @property-read \Illuminate\Database\Eloquent\Collection<int, \App\Models\CloudflareCachePurge> $cachePurges
 */
class SiteCloudflare extends Model
{
    use HasFactory;

    protected $table = 'site_cloudflare';

    protected $fillable = [
        'site_id',
        'cloudflare_connection_id',
        'zone_id',
        'zone_name',
        'plan_type',
        'status',
        'is_paused',
        'ssl_mode',
        'cache_level',
        'connected_at',
        'is_active',
        'next_sync_at',
        'interval_minutes',
        'last_sync_at',
    ];

    protected $casts = [
        'is_paused' => 'boolean',
        'is_active' => 'boolean',
        'connected_at' => 'datetime',
        'next_sync_at' => 'datetime',
        'last_sync_at' => 'datetime',
        'interval_minutes' => 'integer',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function cloudflareConnection(): BelongsTo
    {
        return $this->belongsTo(CloudflareConnection::class);
    }

    public function cachePurges(): HasMany
    {
        return $this->hasMany(CloudflareCachePurge::class);
    }

    protected function planLabel(): Attribute
    {
        return Attribute::get(fn () => ucfirst($this->plan_type));
    }
}
