<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsCache extends Model
{
    public $timestamps = false;

    protected $table = 'analytics_cache';

    protected $fillable = [
        'site_id',
        'date_range',
        'start_date',
        'end_date',
        'data',
        'fetched_at',
        'expires_at',
    ];

    protected $casts = [
        'data' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'fetched_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
