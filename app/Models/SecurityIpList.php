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
 * @property string $ip_address
 * @property string $list_type
 * @property string|null $reason
 * @property \Illuminate\Support\Carbon|null $expires_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
class SecurityIpList extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'ip_address',
        'list_type',
        'reason',
        'expires_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function scopeWhitelist(Builder $query): Builder
    {
        return $query->where('list_type', 'whitelist');
    }

    public function scopeBlocklist(Builder $query): Builder
    {
        return $query->where('list_type', 'blocklist');
    }

    public function scopeGlobal(Builder $query): Builder
    {
        return $query->whereNull('site_id');
    }

    public function scopeForSite(Builder $query, int $siteId): Builder
    {
        return $query->where(function (Builder $q) use ($siteId) {
            $q->where('site_id', $siteId)->orWhereNull('site_id');
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
        });
    }
}
