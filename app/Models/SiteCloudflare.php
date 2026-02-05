<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
    ];

    protected $casts = [
        'is_paused' => 'boolean',
        'connected_at' => 'datetime',
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
