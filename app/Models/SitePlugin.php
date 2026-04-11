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
 * @property string $file
 * @property string $slug
 * @property string $name
 * @property string|null $version
 * @property string|null $author
 * @property string|null $author_uri
 * @property string|null $plugin_uri
 * @property string|null $description
 * @property bool $is_active
 * @property bool $has_update
 * @property string|null $update_version
 * @property string|null $requires_wp
 * @property string|null $requires_php
 * @property bool $auto_update
 * @property \Illuminate\Support\Carbon|null $wp_org_last_updated
 * @property bool|null $is_on_wp_org
 * @property bool $is_abandoned
 * @property bool $is_closed
 * @property string|null $closed_reason
 * @property \Illuminate\Support\Carbon|null $abandoned_checked_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \App\Models\Site|null $site
 */
class SitePlugin extends Model
{
    use HasFactory;

    protected $fillable = [
        'site_id',
        'file',
        'slug',
        'name',
        'version',
        'author',
        'author_uri',
        'plugin_uri',
        'description',
        'is_active',
        'has_update',
        'update_version',
        'requires_wp',
        'requires_php',
        'auto_update',
        'wp_org_last_updated',
        'is_on_wp_org',
        'is_abandoned',
        'is_closed',
        'closed_reason',
        'abandoned_checked_at',
        'license_key',
        'license_expires_at',
        'license_status',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'has_update' => 'boolean',
        'auto_update' => 'boolean',
        'is_on_wp_org' => 'boolean',
        'is_abandoned' => 'boolean',
        'is_closed' => 'boolean',
        'wp_org_last_updated' => 'datetime',
        'abandoned_checked_at' => 'datetime',
        'license_key' => 'encrypted',
        'license_expires_at' => 'datetime',
    ];

    public function isLicenseExpiring(int $daysThreshold = 30): bool
    {
        return $this->license_expires_at !== null
            && $this->license_expires_at->isFuture()
            && $this->license_expires_at->diffInDays(now()) <= $daysThreshold;
    }

    public function isLicenseExpired(): bool
    {
        return $this->license_expires_at !== null && $this->license_expires_at->isPast();
    }

    public function scopeAbandoned(Builder $query): Builder
    {
        return $query->where('is_abandoned', true);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->where('is_closed', true);
    }

    public function scopeProblematic(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->where('is_abandoned', true)->orWhere('is_closed', true);
        });
    }

    public function scopeWithUpdates(Builder $query): Builder
    {
        return $query->where('has_update', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    public function scopeLicensed(Builder $query): Builder
    {
        return $query->whereNotNull('license_key');
    }

    public function scopeExpiringLicenses(Builder $query, int $days = 30): Builder
    {
        return $query->whereNotNull('license_key')
            ->whereNotNull('license_expires_at')
            ->where('license_expires_at', '>', now())
            ->where('license_expires_at', '<=', now()->addDays($days));
    }

    public function scopeExpiredLicenses(Builder $query): Builder
    {
        return $query->whereNotNull('license_key')
            ->whereNotNull('license_expires_at')
            ->where('license_expires_at', '<', now());
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
