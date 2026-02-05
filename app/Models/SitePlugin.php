<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitePlugin extends Model
{
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
    ];

    public function scopeAbandoned($query)
    {
        return $query->where('is_abandoned', true);
    }

    public function scopeClosed($query)
    {
        return $query->where('is_closed', true);
    }

    public function scopeProblematic($query)
    {
        return $query->where(function ($q) {
            $q->where('is_abandoned', true)->orWhere('is_closed', true);
        });
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
