<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SecurityMonitor extends Model
{
    protected $fillable = [
        'site_id',
        'is_active',
        'interval_minutes',
        'next_scan_at',
        'last_scan_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'interval_minutes' => 'integer',
        'next_scan_at' => 'datetime',
        'last_scan_at' => 'datetime',
    ];

    public function site(): BelongsTo
    {
        return $this->belongsTo(Site::class);
    }
}
