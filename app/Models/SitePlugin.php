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
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'has_update' => 'boolean',
        'auto_update' => 'boolean',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
