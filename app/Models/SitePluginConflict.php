<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SitePluginConflict extends Model
{
    protected $fillable = [
        'site_id',
        'plugin_a_slug',
        'plugin_b_slug',
        'plugin_conflict_id',
        'status',
        'detected_at',
    ];

    protected $casts = [
        'detected_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }

    public function conflict(): BelongsTo
    {
        return $this->belongsTo(PluginConflict::class, 'plugin_conflict_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
